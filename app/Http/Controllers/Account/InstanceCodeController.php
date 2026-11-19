<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\CreateInstanceCodeRequest;
use App\Models\Account;
use App\Models\Event;
use App\Models\InstanceRegistrationCode;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;

class InstanceCodeController extends Controller
{
    /** Roles allowed to register instances for an organization. */
    public const ROLES = [Membership::ROLE_OWNER, Membership::ROLE_ADMIN];

    /**
     * POST /v1/account/instance-codes — mints a single-use, 15-minute registration code
     * for one of the caller's organizations (default: the token's organization, else the
     * personal one). Requires the `instance:register` scope and the owner or admin role.
     */
    public function store(CreateInstanceCodeRequest $request): JsonResponse
    {
        /** @var Account $account */
        $account = $request->user();
        $token = $account->currentToken();
        $identifier = $request->input('organization');

        if ($identifier === null && $token?->organization_uuid) {
            $identifier = $token->organization_uuid;
        }

        $organization = $identifier === null
            ? $account->personalOrganization()
            : $account->organizations()
                ->where(fn ($q) => $q->where('organizations.uuid', $identifier)
                    ->orWhere('organizations.public_id', $identifier)
                    ->orWhere('organizations.slug', $identifier))
                ->first();

        if (! $organization instanceof Organization) {
            return response()->error('You are not a member of that organization.', 422);
        }

        if ($token?->organization_uuid && $token->organization_uuid !== $organization->uuid) {
            return response()->error('This token is limited to another organization.', 403);
        }

        if (! in_array($this->roleIn($account, $organization), self::ROLES, true)) {
            return response()->error('Only organization owners and admins can register instances.', 403);
        }

        $code = InstanceRegistrationCode::mint($organization, $account, $request->ip());
        Event::record('instance_code.created', $organization, $account, ['code' => $code->uuid], $request->ip());

        return response()->json([
            'code' => $code->plainTextCode,
            'organization' => $organization->public_id,
            'expires_at' => $code->expires_at,
            'expires_in' => InstanceRegistrationCode::LIFETIME_MINUTES * 60,
        ], 201);
    }

    protected function roleIn(Account $account, Organization $organization): ?string
    {
        return Membership::query()
            ->where('account_uuid', $account->uuid)
            ->where('organization_uuid', $organization->uuid)
            ->value('role');
    }
}
