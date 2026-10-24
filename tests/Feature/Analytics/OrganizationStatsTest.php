<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Release;
use App\Models\Token;
use Illuminate\Support\Carbon;

/**
 * An organization with two live listings and two deleted ones (one sold in October, one idle),
 * "today" being 23 October 2026. Returns [owner, organization, route optimizer, pallet wms, retired].
 */
function organizationWithListings(): array
{
    test()->travelTo(Carbon::parse('2026-10-23 12:00:00'));
    [$owner, $organization, $routes, [$r1, $r2]] = statsListing(extension: ['name' => 'Route Optimizer', 'slug' => 'route-optimizer']);
    $pallet = Extension::factory()->published()->free()->for($organization)->create(['name' => 'Pallet WMS', 'slug' => 'pallet-wms']);
    $p1 = Release::factory()->published()->create(['extension_uuid' => $pallet->uuid, 'version' => '2.0.0', 'compat' => []]);
    $retired = Extension::factory()->published()->free()->for($organization)->create(['name' => 'Retired', 'slug' => 'retired']);
    $idle = Extension::factory()->published()->free()->for($organization)->create(['name' => 'Idle', 'slug' => 'idle']);

    statsInstall($routes, $r1, '2026-10-02 10:00:00');
    statsInstall($routes, $r2, '2026-10-12 10:00:00', ['uninstalled_at' => '2026-10-14 10:00:00']);
    statsInstall($pallet, $p1, '2026-10-05 10:00:00');
    statsInstall($pallet, $p1, '2026-10-06 10:00:00');
    statsInstall($pallet, $p1, '2026-09-15 10:00:00');

    [, $buyer] = buyerOrganization();
    test()->travelTo(Carbon::parse('2026-10-08 12:00:00'));
    recordedSale($routes, $buyer);
    test()->travelTo(Carbon::parse('2026-10-09 12:00:00'));
    recordedSale($retired, $buyer, 2000, 200);
    $retired->delete();
    $idle->delete();
    // Another organization's listing never counts.
    [, , $foreign, [$f1]] = statsListing();
    statsInstall($foreign, $f1, '2026-10-03 10:00:00');
    test()->travelTo(Carbon::parse('2026-10-23 12:00:00'));

    return [$owner, $organization, $routes, $pallet, $retired];
}

it('sums every listing of the organization, with a row per listing', function () {
    [$owner, $organization, $routes, $pallet, $retired] = organizationWithListings();
    actingAsAccount($owner);

    $response = $this->getJson("/v1/organizations/{$organization->slug}/stats?from=2026-10-01&to=2026-10-23&group=week")
        ->assertOk()
        ->assertJsonPath('organization', ['id' => $organization->public_id, 'slug' => $organization->slug, 'name' => $organization->name])
        ->assertJsonPath('range.group', 'week')
        ->assertJsonPath('totals', [
            'installs' => 4,
            'updates' => 0,
            'uninstalls' => 1,
            'purchases' => 2,
            'revenue' => [['currency' => 'USD', 'purchases' => 2, 'gross' => 7292, 'refunded' => 0, 'fee' => 690, 'net' => 6602]],
            'active_instances' => 4,
        ])
        ->assertJsonPath('previous_totals.installs', 1)
        ->assertJsonPath('previous_totals.active_instances', 1)
        ->assertJsonPath('events.total', 7)
        ->assertJsonMissingPath('versions');

    expect(array_column($response->json('series'), 'installs'))->toBe([1, 2, 1, 0]);

    $rows = $response->json('extensions');
    expect(array_map(fn ($row) => $row['extension']['slug'], $rows))->toBe(['pallet-wms', 'route-optimizer', 'retired'])
        ->and($rows[0])->toMatchArray(['installs' => 2, 'uninstalls' => 0, 'purchases' => 0, 'active_instances' => 3, 'revenue' => []])
        ->and($rows[1])->toMatchArray(['installs' => 2, 'uninstalls' => 1, 'purchases' => 1, 'active_instances' => 1])
        ->and($rows[1]['revenue'][0])->toMatchArray(['currency' => 'USD', 'net' => 4802])
        ->and($rows[2]['purchases'])->toBe(1)
        ->and($response->json('events.data.0.extension.slug'))->toBe('route-optimizer')
        ->and($response->json('events.data.0.type'))->toBe('uninstall');
});

it('exports the organization series as CSV', function () {
    [$owner, $organization] = organizationWithListings();
    actingAsAccount($owner);

    $response = $this->get("/v1/organizations/{$organization->slug}/stats.csv?from=2026-10-01&to=2026-10-31&group=month")->assertOk();

    expect(array_map('str_getcsv', explode("\n", trim($response->streamedContent()))))->toBe([
        ['period', 'installs', 'updates', 'uninstalls', 'purchases', 'gross_USD', 'fee_USD', 'net_USD'],
        ['2026-10-01', '4', '0', '1', '2', '72.92', '6.90', '66.02'],
    ]);
});

it('lets owners, admins, publishers and billing members see organization stats', function (string $role) {
    [, $organization] = organizationWithListings();
    $member = Account::factory()->create();
    $member->organizations()->attach($organization->uuid, ['role' => $role]);
    actingAsAccount($member);

    $this->getJson("/v1/organizations/{$organization->slug}/stats")->assertOk()->assertJsonPath('totals.installs', 4);
})->with([Membership::ROLE_OWNER, Membership::ROLE_ADMIN, Membership::ROLE_PUBLISHER, Membership::ROLE_BILLING]);

it('refuses plain members, strangers and tokens pinned to another organization', function () {
    [$owner, $organization] = organizationWithListings();
    $url = "/v1/organizations/{$organization->slug}/stats";

    $member = Account::factory()->create();
    $member->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_MEMBER]);
    actingAsAccount($member);
    $this->getJson($url)->assertForbidden();

    actingAsAccount(Account::factory()->create());
    $this->getJson($url)->assertNotFound();

    $other = Organization::factory()->create();
    $owner->organizations()->attach($other->uuid, ['role' => Membership::ROLE_OWNER]);
    $token = Token::issue($owner, 'pinned', [Token::SCOPE_ALL], null, $other->uuid);
    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson($url)->assertForbidden();
});
