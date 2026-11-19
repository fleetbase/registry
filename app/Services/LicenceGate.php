<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Token;
use Illuminate\Http\Request;

/**
 * Decides whether a package request may read a paid or private extension.
 *
 * The bearer credential resolves to one or more organizations:
 *   - personal token (`flbp_…`) with the `install` scope: the token's organization when it
 *     is pinned to one, otherwise every organization the account belongs to;
 *   - instance token (`flbi_…`): the instance's organization, resolved by
 *     `Instance::findByToken()` so hashing and revocation live on the model.
 * Access is granted when one of those organizations owns the extension or holds an active,
 * unexpired licence for it that covers the caller (`ExtensionAccess::holdsAccess`: a grant
 * limited to instances only covers those instances; a private extension only counts grants and
 * paid licences). Seats (licence activations) are not checked here: they are claimed when the
 * instance reports the install (`POST /v1/instances/me/installs`).
 *
 * Paid public extensions answer 401 without a usable token and 403 without a licence. Private
 * extensions answer 404 to every refused caller, so their names never leak. Free public
 * extensions never reach this check.
 */
class LicenceGate
{
    public const STATUS_ALLOWED = 200;

    public const STATUS_UNAUTHENTICATED = 401;

    public const STATUS_FORBIDDEN = 403;

    public const STATUS_NOT_FOUND = 404;

    public const NOT_FOUND_MESSAGE = 'Package not found.';

    public function __construct(protected ExtensionAccess $access) {}

    /**
     * @return array{status: int, message: ?string}
     */
    public function check(Request $request, Extension $extension): array
    {
        $plain = (string) $request->bearerToken();
        if ($plain === '') {
            return $this->deny($extension, self::STATUS_UNAUTHENTICATED, "{$extension->name} is a paid extension. Send a registry token with the install scope as `Authorization: Bearer <token>`.");
        }

        $credential = $this->resolve($plain, $request);
        if ($credential === null) {
            return $this->deny($extension, self::STATUS_UNAUTHENTICATED, 'The registry token is invalid, expired or revoked.');
        }

        if ($credential instanceof Token && ! $credential->can(Token::SCOPE_INSTALL)) {
            return $this->deny($extension, self::STATUS_FORBIDDEN, "This token does not have the 'install' scope.");
        }

        if ($this->allows($credential, $extension)) {
            return ['status' => self::STATUS_ALLOWED, 'message' => null];
        }

        return $this->deny($extension, self::STATUS_FORBIDDEN, "Your organization does not hold an active licence for {$extension->name}.");
    }

    /**
     * Whether an authenticated credential may read the extension: free public extensions always;
     * paid or private ones when an organization of the credential owns the extension or holds a
     * licence covering it (`ExtensionAccess::holdsAccess`: grants and paid licences only for a
     * private extension, instance-limited grants only for those instances), or when the cloud
     * platform instance holds an active seat for one of its companies.
     */
    public function allows(Token|Instance $credential, Extension $extension): bool
    {
        if (! $extension->isPaid() && ! $extension->isPrivate()) {
            return true;
        }

        $instance = $credential instanceof Instance ? $credential : null;

        return $this->access->holdsAccess($extension, $this->organizationsFor($credential), $instance)
            || $this->platformHoldsSeat($credential, $extension);
    }

    /**
     * @param  list<string>  $organizationUuids
     */
    public function hasActiveLicence(Extension $extension, array $organizationUuids, ?Instance $instance = null): bool
    {
        return $this->access->holdsAccess($extension, $organizationUuids, $instance);
    }

    /**
     * The cloud instance downloads for whichever company it is installing for, and Composer and
     * npm cannot name that company. It may read a paid package while any company holds an active
     * seat on it: the install report that claims the seat comes before the download.
     */
    protected function platformHoldsSeat(Token|Instance $credential, Extension $extension): bool
    {
        return $credential instanceof Instance
            && $credential->isPlatform()
            && $credential->activations()
                ->whereNull('deactivated_at')
                ->whereHas('licence', fn ($q) => $q->active()->where('extension_uuid', $extension->uuid))
                ->exists();
    }

    protected function resolve(string $plain, Request $request): Token|Instance|null
    {
        if (str_starts_with($plain, Token::PREFIX_PERSONAL)) {
            $token = Token::findActive($plain);
            if (! $token || ! $token->account instanceof Account || ! $token->account->isActive()) {
                return null;
            }
            $token->touchUsage($request->ip());

            return $token;
        }

        return Instance::findByToken($plain);
    }

    /**
     * @return list<string>
     */
    protected function organizationsFor(Token|Instance $credential): array
    {
        if ($credential instanceof Instance) {
            return [$credential->organization_uuid];
        }

        return ExtensionAccess::tokenOrganizations($credential);
    }

    protected function deny(Extension $extension, int $status, string $message): array
    {
        if ($extension->isPrivate()) {
            return ['status' => self::STATUS_NOT_FOUND, 'message' => self::NOT_FOUND_MESSAGE];
        }

        return ['status' => $status, 'message' => $message];
    }
}
