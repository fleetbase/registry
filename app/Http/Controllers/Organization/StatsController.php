<?php

namespace App\Http\Controllers\Organization;

use App\Analytics\ExtensionStats;
use App\Analytics\StatsCsv;
use App\Analytics\StatsRange;
use App\Http\Controllers\Controller;
use App\Models\Extension;
use App\Models\Membership;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics across every listing of an organization (deleted listings included, so past sales
 * still add up), with one row per listing. Owners, admins, publishers and billing members.
 */
class StatsController extends Controller
{
    use ActsForOrganization;

    public const ROLES = [Membership::ROLE_OWNER, Membership::ROLE_ADMIN, Membership::ROLE_PUBLISHER, Membership::ROLE_BILLING];

    /**
     * GET /v1/organizations/{slug}/stats?from=&to=&group=day|week|month
     */
    public function show(Request $request, string $slug): JsonResponse
    {
        $organization = $this->statsOrganization($request, $slug);
        $range = StatsRange::fromRequest($request);

        return response()->json([
            'organization' => ['id' => $organization->public_id, 'slug' => $organization->slug, 'name' => $organization->name],
        ] + $this->report($organization, $range));
    }

    /**
     * GET /v1/organizations/{slug}/stats.csv?from=&to=&group= — the series as CSV.
     */
    public function export(Request $request, string $slug): StreamedResponse
    {
        $organization = $this->statsOrganization($request, $slug);
        $range = StatsRange::fromRequest($request);

        return StatsCsv::download($this->report($organization, $range), StatsCsv::filename($organization->slug, $range));
    }

    protected function statsOrganization(Request $request, string $slug): Organization
    {
        $organization = $this->findOrganization($request, $slug);
        $this->assertRole($organization, self::ROLES, 'Only organization owners, admins, publishers and billing members can see analytics.');

        return $organization;
    }

    protected function report(Organization $organization, StatsRange $range): array
    {
        $extensions = Extension::withTrashed()->where('organization_uuid', $organization->uuid)->orderBy('name')->get();

        return (new ExtensionStats($extensions))->cachedReport($range, perExtension: true);
    }
}
