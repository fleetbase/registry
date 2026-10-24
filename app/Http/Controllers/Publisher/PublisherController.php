<?php

namespace App\Http\Controllers\Publisher;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Extension;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Release;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Publisher routes act for an organization the caller belongs to with the owner, admin or
 * publisher role. A token pinned to an organization only reaches that organization.
 * Listings of organizations the caller is not in answer 404.
 */
abstract class PublisherController extends Controller
{
    protected function account(Request $request): Account
    {
        return $request->user();
    }

    /**
     * Organizations the caller may publish for, honouring the token's organization pin.
     *
     * @return list<string> organization uuids
     */
    protected function publishableOrganizations(Request $request): array
    {
        $account = $this->account($request);
        $pinned = $account->currentToken()?->organization_uuid;

        return Membership::query()
            ->where('account_uuid', $account->uuid)
            ->whereIn('role', Membership::PUBLISHING_ROLES)
            ->when($pinned, fn (Builder $q) => $q->where('organization_uuid', $pinned))
            ->pluck('organization_uuid')
            ->all();
    }

    /**
     * The organization a new listing is created in: `organization` from the body, else the
     * token's organization, else the caller's personal organization.
     */
    protected function targetOrganization(Request $request, ?string $identifier): Organization
    {
        $account = $this->account($request);
        $token = $account->currentToken();
        $identifier ??= $token?->organization_uuid;

        $organization = $identifier === null
            ? $account->personalOrganization()
            : $account->organizations()
                ->where(fn ($q) => $q->where('organizations.uuid', $identifier)
                    ->orWhere('organizations.public_id', $identifier)
                    ->orWhere('organizations.slug', $identifier))
                ->first();

        if (! $organization instanceof Organization) {
            throw new UnprocessableEntityHttpException('You are not a member of that organization.');
        }

        if ($token?->organization_uuid && $token->organization_uuid !== $organization->uuid) {
            throw new AccessDeniedHttpException('This token is limited to another organization.');
        }

        if (! in_array($account->roleIn($organization), Membership::PUBLISHING_ROLES, true)) {
            throw new AccessDeniedHttpException('Only organization owners, admins and publishers can publish extensions.');
        }

        return $organization;
    }

    protected function findExtension(Request $request, string $slug, array $with = []): Extension
    {
        $extension = Extension::query()
            ->with($with)
            ->where(fn (Builder $q) => $q->where('slug', $slug)->orWhere('public_id', $slug))
            ->first();

        if ($extension === null || ! $this->canPublishFor($request, $extension->organization_uuid)) {
            throw new NotFoundHttpException('Extension not found.');
        }

        return $extension;
    }

    protected function findRelease(Request $request, string $id): Release
    {
        $release = Release::findById($id, ['extension']);

        if ($release === null || $release->extension === null || ! $this->canPublishFor($request, $release->extension->organization_uuid)) {
            throw new NotFoundHttpException('Release not found.');
        }

        return $release;
    }

    protected function canPublishFor(Request $request, string $organizationUuid): bool
    {
        return in_array($organizationUuid, $this->publishableOrganizations($request), true);
    }

    /** The listing relations the release detail resource shows (the staff listing preview). */
    public const RELEASE_LISTING = ['extension.organization', 'extension.category', 'extension.price', 'extension.icon', 'extension.screenshots'];

    /**
     * Relations the release detail resource reads.
     */
    public static function releaseDetail(Release $release): Release
    {
        return $release->load(array_merge(self::RELEASE_LISTING, ['engineTarball', 'apiTarball', 'engineDist', 'checks', 'reviews.reviewer']));
    }
}
