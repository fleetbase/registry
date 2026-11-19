<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\CreateOrganizationRequest;
use App\Http\Requests\Organization\MemberRequest;
use App\Http\Requests\Organization\UpdateOrganizationRequest;
use App\Http\Resources\MemberResource;
use App\Http\Resources\OrganizationResource;
use App\Models\Account;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Support\Utils;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * The caller's organizations and their members. Organizations are addressed by slug or
 * public id; members by account public id (or username). Only members see an organization
 * (others get 404); owners and admins edit it and manage members, and only owners grant,
 * change or remove the owner role. An organization always keeps at least one owner.
 * A token pinned to an organization reaches only that organization.
 */
class OrganizationController extends Controller
{
    use ActsForOrganization;

    /** Roles that may edit the organization and manage its members. */
    public const MANAGING_ROLES = [Membership::ROLE_OWNER, Membership::ROLE_ADMIN];

    /**
     * GET /v1/organizations — the caller's organizations with their role and member count.
     */
    public function index(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $pinned = $account->currentToken()?->organization_uuid;

        $organizations = $account->organizations()
            ->withCount('memberships')
            ->when($pinned, fn (Builder $q) => $q->where('organizations.uuid', $pinned))
            ->orderByDesc('is_personal')
            ->orderBy('name')
            ->get();

        return response()->json(OrganizationResource::collection($organizations)->resolve($request));
    }

    /**
     * POST /v1/organizations — name, optional slug, website, bio. The caller becomes its owner.
     */
    public function store(CreateOrganizationRequest $request): JsonResponse
    {
        $account = $this->account($request);
        if ($account->currentToken()?->organization_uuid) {
            throw new AccessDeniedHttpException('This token is limited to another organization.');
        }

        $organization = DB::transaction(function () use ($request, $account) {
            $organization = Organization::create([
                'slug' => $request->input('slug') ?? $this->uniqueSlug($request->input('name')),
                'name' => $request->input('name'),
                'website' => $request->input('website'),
                'bio' => $request->input('bio'),
                'is_personal' => false,
            ]);
            $account->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_OWNER]);

            return $organization;
        });

        Event::record('organization.created', $organization, $account, ['slug' => $organization->slug], $request->ip());

        return response()->json($this->detail($request, $organization->slug)->resolve($request), 201);
    }

    /**
     * GET /v1/organizations/{slug} — the profile, the caller's role and the member list.
     */
    public function show(Request $request, string $slug): OrganizationResource
    {
        return $this->detail($request, $slug);
    }

    /**
     * PATCH /v1/organizations/{slug} — name, website, bio, avatar_url. Owners and admins;
     * the slug never changes.
     */
    public function update(UpdateOrganizationRequest $request, string $slug): OrganizationResource
    {
        $organization = $this->findOrganization($request, $slug);
        $this->assertManages($organization);

        $attributes = collect($request->validated())->only(['name', 'website', 'bio', 'avatar_url'])->all();
        $organization->fill($attributes)->save();
        Event::record('organization.updated', $organization, $this->account($request), ['fields' => array_keys($attributes)], $request->ip());

        return $this->detail($request, $organization->slug);
    }

    /**
     * POST /v1/organizations/{slug}/members — email, role. Owners and admins; the email must
     * belong to a registry account. Personal organizations have no other members.
     */
    public function addMember(MemberRequest $request, string $slug): JsonResponse
    {
        $organization = $this->findOrganization($request, $slug);
        $this->assertManages($organization);

        if ($organization->is_personal) {
            throw new UnprocessableEntityHttpException('Personal organizations cannot have other members. Create an organization to work with others.');
        }

        $role = $request->input('role');
        $this->assertMayAssign($organization, $role);

        $member = Account::query()->where('email', Str::lower($request->input('email')))->first();
        if ($member === null) {
            throw new UnprocessableEntityHttpException('No registry account uses that email. Ask them to register first.');
        }

        if ($member->roleIn($organization) !== null) {
            throw new ConflictHttpException("{$member->email} is already a member.");
        }

        $membership = Membership::create(['organization_uuid' => $organization->uuid, 'account_uuid' => $member->uuid, 'role' => $role]);
        Event::record('organization.member_added', $organization, $this->account($request), ['account' => $member->public_id, 'role' => $role], $request->ip());

        return response()->json((new MemberResource($membership->load('account')))->resolve($request), 201);
    }

    /**
     * PATCH /v1/organizations/{slug}/members/{account} — role. Owners and admins; only
     * owners grant or change the owner role, and the last owner cannot be demoted.
     */
    public function updateMember(MemberRequest $request, string $slug, string $member): MemberResource
    {
        $organization = $this->findOrganization($request, $slug);
        $this->assertManages($organization);

        $membership = $this->findMembership($organization, $member);
        $role = $request->input('role');

        $from = $membership->role;

        if ($from !== $role) {
            $this->assertMayAssign($organization, $role);
            if ($from === Membership::ROLE_OWNER) {
                $this->assertMayAssign($organization, Membership::ROLE_OWNER);
            }

            DB::transaction(function () use ($organization, $membership, $role, $from) {
                if ($from === Membership::ROLE_OWNER) {
                    $this->assertNotLastOwner($organization, 'demoted');
                }
                $membership->forceFill(['role' => $role])->save();
            });

            Event::record('organization.member_role_changed', $organization, $this->account($request), [
                'account' => $membership->account->public_id,
                'from' => $from,
                'to' => $role,
            ], $request->ip());
        }

        return new MemberResource($membership->load('account'));
    }

    /**
     * DELETE /v1/organizations/{slug}/members/{account} — owners and admins, or members
     * leaving. Removing another owner takes an owner; the last owner cannot be removed.
     */
    public function removeMember(Request $request, string $slug, string $member): JsonResponse
    {
        $organization = $this->findOrganization($request, $slug);
        $membership = $this->findMembership($organization, $member);
        $account = $this->account($request);
        $leaving = $membership->account_uuid === $account->uuid;

        if (! $leaving) {
            $this->assertManages($organization);
            if ($membership->role === Membership::ROLE_OWNER) {
                $this->assertMayAssign($organization, Membership::ROLE_OWNER);
            }
        }

        DB::transaction(function () use ($organization, $membership) {
            if ($membership->role === Membership::ROLE_OWNER) {
                $this->assertNotLastOwner($organization, 'removed');
            }
            $membership->delete();
        });

        Event::record($leaving ? 'organization.member_left' : 'organization.member_removed', $organization, $account, [
            'account' => $membership->account->public_id,
            'role' => $membership->role,
        ], $request->ip());

        return response()->json(['removed' => true, 'id' => $membership->account->public_id]);
    }

    protected function detail(Request $request, string $identifier): OrganizationResource
    {
        $organization = $this->findOrganization($request, $identifier, ['memberships' => fn ($q) => $q->with('account')->orderBy('created_at')]);
        $organization->loadCount('memberships');

        // Owners first, then by role and name.
        $order = array_flip(Membership::ROLES);
        $organization->setRelation('memberships', $organization->memberships->sortBy([
            fn ($a, $b) => ($order[$a->role] ?? 99) <=> ($order[$b->role] ?? 99),
            fn ($a, $b) => strcasecmp((string) $a->account?->name, (string) $b->account?->name),
        ])->values());

        return new OrganizationResource($organization);
    }

    protected function findMembership(Organization $organization, string $identifier): Membership
    {
        $membership = Membership::query()
            ->with('account')
            ->where('organization_uuid', $organization->uuid)
            ->whereHas('account', fn (Builder $q) => $q->where('public_id', $identifier)
                ->orWhere('uuid', $identifier)
                ->orWhere('username', $identifier))
            ->first();

        if ($membership === null) {
            throw new NotFoundHttpException('Member not found.');
        }

        return $membership;
    }

    protected function assertManages(Organization $organization): void
    {
        if (! in_array($organization->pivot?->role, self::MANAGING_ROLES, true)) {
            throw new AccessDeniedHttpException('Only organization owners and admins can do this.');
        }
    }

    protected function assertMayAssign(Organization $organization, string $role): void
    {
        if ($role === Membership::ROLE_OWNER && $organization->pivot?->role !== Membership::ROLE_OWNER) {
            throw new AccessDeniedHttpException('Only owners can grant or change the owner role.');
        }
    }

    protected function assertNotLastOwner(Organization $organization, string $action): void
    {
        $owners = Membership::query()
            ->where('organization_uuid', $organization->uuid)
            ->where('role', Membership::ROLE_OWNER)
            ->lockForUpdate()
            ->count();

        if ($owners <= 1) {
            throw new UnprocessableEntityHttpException("The last owner cannot be {$action}. Make another member an owner first.");
        }
    }

    protected function uniqueSlug(string $name): string
    {
        $base = Utils::slugify($name);
        $reserved = config('registry.reserved_scopes', []);
        $candidate = $base;

        while (in_array($candidate, $reserved, true) || Organization::withTrashed()->where('slug', $candidate)->exists()) {
            $candidate = $base.'-'.Str::lower(Str::random(4));
        }

        return $candidate;
    }
}
