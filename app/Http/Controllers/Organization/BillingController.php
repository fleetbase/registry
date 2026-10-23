<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Resources\LicenceResource;
use App\Http\Resources\PurchaseResource;
use App\Models\Licence;
use App\Models\Organization;
use App\Models\Purchase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What an organization bought: purchases with who bought them and Stripe's receipts (owners,
 * admins and billing members), and its licences with the instances holding a seat (any member).
 */
class BillingController extends Controller
{
    use ActsForOrganization;

    /**
     * GET /v1/organizations/{slug}/billing/purchases — newest first.
     */
    public function purchases(Request $request, string $slug): JsonResponse
    {
        $organization = $this->billingOrganization($request, $slug);

        $purchases = Purchase::query()
            ->where('buyer_organization_uuid', $organization->uuid)
            ->with(['extension', 'buyer', 'buyerAccount', 'licence'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        return response()->json(PurchaseResource::collection($purchases)->resolve($request));
    }

    /**
     * GET /v1/organizations/{slug}/billing/licences — paid and free licences with seat use and
     * the instances activated on each. Any member.
     */
    public function licences(Request $request, string $slug): JsonResponse
    {
        $organization = $this->findOrganization($request, $slug);

        $licences = Licence::query()
            ->where('organization_uuid', $organization->uuid)
            ->with(['extension', 'purchase', 'activeActivations' => fn ($q) => $q->with(['instance', 'release'])->orderBy('activated_at')])
            ->withCount('activeActivations')
            ->orderByRaw('case when kind = ? then 0 else 1 end', [Licence::KIND_PAID])
            ->orderByDesc('created_at')
            ->get();

        return response()->json(LicenceResource::collection($licences)->resolve($request));
    }

    protected function billingOrganization(Request $request, string $slug): Organization
    {
        $organization = $this->findOrganization($request, $slug);
        $this->assertRole($organization, self::BILLING_ROLES, 'Only organization owners, admins and billing members can see billing.');

        return $organization;
    }
}
