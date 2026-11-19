<?php

namespace App\Http\Controllers\Organization;

use App\Models\Account;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Routes under /v1/organizations/{slug}: the organization must be one of the caller's (others
 * answer 404), a token pinned to another organization is refused, and the caller's role is on
 * the organization's `pivot`.
 */
trait ActsForOrganization
{
    /** Roles that see and manage an organization's money: payouts, purchases, licences. */
    public const BILLING_ROLES = [Membership::ROLE_OWNER, Membership::ROLE_ADMIN, Membership::ROLE_BILLING];

    protected function account(Request $request): Account
    {
        return $request->user();
    }

    /**
     * One of the caller's organizations by slug, public id or uuid, with the caller's
     * membership as `pivot`. Organizations the caller is not in answer 404.
     */
    protected function findOrganization(Request $request, string $identifier, array $with = []): Organization
    {
        $account = $this->account($request);

        $organization = $account->organizations()
            ->with($with)
            ->where(fn (Builder $q) => $q->where('organizations.slug', $identifier)
                ->orWhere('organizations.public_id', $identifier)
                ->orWhere('organizations.uuid', $identifier))
            ->first();

        if (! $organization instanceof Organization) {
            throw new NotFoundHttpException('Organization not found.');
        }

        $pinned = $account->currentToken()?->organization_uuid;
        if ($pinned && $pinned !== $organization->uuid) {
            throw new AccessDeniedHttpException('This token is limited to another organization.');
        }

        return $organization;
    }

    /**
     * @param  list<string>  $roles
     */
    protected function assertRole(Organization $organization, array $roles, string $message): void
    {
        if (! in_array($organization->pivot?->role, $roles, true)) {
            throw new AccessDeniedHttpException($message);
        }
    }
}
