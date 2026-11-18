<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Token;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;

/**
 * Who may see a private extension.
 *
 * A caller (`Viewer`) is the organizations behind its credential and, for an instance token,
 * the instance:
 *   - instance token (`flbi_`): the instance's organization;
 *   - personal token (`flbp_`) with the `read` or `install` scope: the organization it is
 *     pinned to, else every organization of the account;
 *   - the website session (or an in-process call carrying its user): the account's
 *     organizations;
 *   - anything else: nobody.
 *
 * A private extension is visible to its owner organization, and to an organization holding an
 * active `grant` or `paid` licence for it that covers the caller (`Licence::covers`: a grant
 * limited to instances covers only those instance tokens). Automatic `free` licences never
 * open a private extension. Every listing and protocol answers 404 for a private extension the
 * caller cannot see, so its name never leaks.
 *
 * Results are memoised per request: the catalog asks several times while building one page.
 */
class ExtensionAccess
{
    /** Licence kinds that open a private extension. */
    public const PRIVATE_ACCESS_KINDS = [Licence::KIND_GRANT, Licence::KIND_PAID];

    protected const VIEWER_ATTRIBUTE = 'extension_access.viewer';

    public function viewer(Request $request): Viewer
    {
        $viewer = $request->attributes->get(self::VIEWER_ATTRIBUTE);
        if ($viewer instanceof Viewer) {
            return $viewer;
        }

        $viewer = $this->resolveViewer($request);
        $request->attributes->set(self::VIEWER_ATTRIBUTE, $viewer);

        return $viewer;
    }

    /**
     * Uuids of the private extensions the viewer may see, sorted (part of catalog cache keys).
     *
     * @return list<string>
     */
    public function privateExtensionUuids(Viewer $viewer): array
    {
        if ($viewer->privateUuids !== null) {
            return $viewer->privateUuids;
        }

        if ($viewer->organizationUuids === []) {
            return $viewer->privateUuids = [];
        }

        $owned = Extension::query()
            ->where('visibility', Extension::VISIBILITY_PRIVATE)
            ->whereIn('organization_uuid', $viewer->organizationUuids)
            ->pluck('uuid');

        $licensed = Licence::query()->active()
            ->whereIn('kind', self::PRIVATE_ACCESS_KINDS)
            ->whereIn('organization_uuid', $viewer->organizationUuids)
            ->whereIn('extension_uuid', Extension::query()->where('visibility', Extension::VISIBILITY_PRIVATE)->select('uuid'))
            ->get(['uuid', 'extension_uuid', 'instance_uuids'])
            ->filter(fn (Licence $licence) => $licence->covers($viewer->instance))
            ->pluck('extension_uuid');

        $uuids = $owned->merge($licensed)->unique()->sort()->values()->all();

        return $viewer->privateUuids = $uuids;
    }

    /**
     * Limit a listing query to public listings plus the private ones the viewer may see.
     */
    public function visible(Builder|Relation $query, Viewer $viewer): Builder|Relation
    {
        $private = $this->privateExtensionUuids($viewer);
        $table = $query->getModel()->getTable();

        return $query->where(fn ($q) => $q->where("{$table}.visibility", Extension::VISIBILITY_PUBLIC)
            ->when($private !== [], fn ($q) => $q->orWhereIn("{$table}.uuid", $private)));
    }

    public function canSee(Viewer $viewer, Extension $extension): bool
    {
        return ! $extension->isPrivate() || in_array($extension->uuid, $this->privateExtensionUuids($viewer), true);
    }

    /**
     * Whether the organizations own the extension or hold a licence for it that covers the
     * instance. For a private extension only grants and paid licences count; for a public one
     * any active licence does (a free licence on an extension that became paid keeps working).
     *
     * @param  list<string>  $organizationUuids
     */
    public function holdsAccess(Extension $extension, array $organizationUuids, ?Instance $instance): bool
    {
        if ($organizationUuids === []) {
            return false;
        }

        if (in_array($extension->organization_uuid, $organizationUuids, true)) {
            return true;
        }

        return Licence::query()->active()
            ->where('extension_uuid', $extension->uuid)
            ->whereIn('organization_uuid', $organizationUuids)
            ->when($extension->isPrivate(), fn (Builder $q) => $q->whereIn('kind', self::PRIVATE_ACCESS_KINDS))
            ->get(['uuid', 'instance_uuids'])
            ->contains(fn (Licence $licence) => $licence->covers($instance));
    }

    protected function resolveViewer(Request $request): Viewer
    {
        $plain = (string) $request->bearerToken();

        if (str_starts_with($plain, Token::PREFIX_INSTANCE)) {
            $instance = Instance::findByToken($plain);

            return $instance ? new Viewer([$instance->organization_uuid], $instance) : Viewer::anonymous();
        }

        if (str_starts_with($plain, Token::PREFIX_PERSONAL)) {
            $token = Token::findActive($plain);
            if (! $token || ! $token->account instanceof Account || ! $token->account->isActive()
                || ! ($token->can(Token::SCOPE_READ) || $token->can(Token::SCOPE_INSTALL))) {
                return Viewer::anonymous();
            }

            return new Viewer(self::tokenOrganizations($token));
        }

        if ($plain !== '') {
            return Viewer::anonymous();
        }

        // The website: in-process calls carry the page request's user resolver; public pages
        // do not run `auth:web`, so ask the session guard directly (no session: nobody).
        $user = $request->user() ?? $request->user('web');
        if ($user instanceof Account) {
            return new Viewer($user->memberships()->pluck('organization_uuid')->all());
        }

        return Viewer::anonymous();
    }

    /**
     * @return list<string>
     */
    public static function tokenOrganizations(Token $token): array
    {
        if ($token->organization_uuid) {
            return [$token->organization_uuid];
        }

        return $token->account->memberships()->pluck('organization_uuid')->all();
    }
}
