<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Token;
use Illuminate\Http\Request;

/**
 * Decides whether a package request may read a paid extension.
 *
 * The bearer credential resolves to one or more organizations:
 *   - personal token (`flbp_…`) with the `install` scope: the token's organization when it
 *     is pinned to one, otherwise every organization the account belongs to;
 *   - instance token (`flbi_…`): the instance's organization, resolved by
 *     `Instance::findByToken()` so hashing and revocation live on the model.
 * Access is granted when one of those organizations owns the extension or holds an active,
 * unexpired licence for it. Seats (licence activations) are not checked here: they are
 * claimed when the instance reports the install (`POST /v1/instances/me/installs`).
 * Free extensions never reach this check.
 */
class LicenceGate
{
    public const STATUS_ALLOWED = 200;

    public const STATUS_UNAUTHENTICATED = 401;

    public const STATUS_FORBIDDEN = 403;

    /**
     * @return array{status: int, message: ?string}
     */
    public function check(Request $request, Extension $extension): array
    {
        $plain = (string) $request->bearerToken();
        if ($plain === '') {
            return $this->deny(self::STATUS_UNAUTHENTICATED, "{$extension->name} is a paid extension. Send a registry token with the install scope as `Authorization: Bearer <token>`.");
        }

        $credential = $this->resolve($plain, $request);
        if ($credential === null) {
            return $this->deny(self::STATUS_UNAUTHENTICATED, 'The registry token is invalid, expired or revoked.');
        }

        if ($credential instanceof Token && ! $credential->can(Token::SCOPE_INSTALL)) {
            return $this->deny(self::STATUS_FORBIDDEN, "This token does not have the 'install' scope.");
        }

        if ($this->allows($credential, $extension)) {
            return ['status' => self::STATUS_ALLOWED, 'message' => null];
        }

        return $this->deny(self::STATUS_FORBIDDEN, "Your organization does not hold an active licence for {$extension->name}.");
    }

    /**
     * Whether an authenticated credential may read the extension: free extensions always; paid
     * ones when an organization of the credential owns the extension or holds an active licence.
     */
    public function allows(Token|Instance $credential, Extension $extension): bool
    {
        if (! $extension->isPaid()) {
            return true;
        }

        $organizations = $this->organizationsFor($credential);

        return in_array($extension->organization_uuid, $organizations, true) || $this->hasActiveLicence($extension, $organizations);
    }

    /**
     * @param  list<string>  $organizationUuids
     */
    public function hasActiveLicence(Extension $extension, array $organizationUuids): bool
    {
        if ($organizationUuids === []) {
            return false;
        }

        return Licence::query()->active()
            ->where('extension_uuid', $extension->uuid)
            ->whereIn('organization_uuid', $organizationUuids)
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

        if ($credential->organization_uuid) {
            return [$credential->organization_uuid];
        }

        return $credential->account->memberships()->pluck('organization_uuid')->all();
    }

    protected function deny(int $status, string $message): array
    {
        return ['status' => $status, 'message' => $message];
    }
}
