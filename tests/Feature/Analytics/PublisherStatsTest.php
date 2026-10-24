<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Token;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * October 2026 on one listing, "today" being 23 October:
 * A installs 1.0.0 on 2 Oct and updates to 1.1.0 on 5 Oct; B installs on 2 Oct and uninstalls on
 * 10 Oct; C installs 1.1.0 on 20 Oct. In September, D installs (still running) and E installs and
 * uninstalls. Sales: USD on 3 Oct, USD refunded in full (sold 4 Oct, refunded 12 Oct), EUR on 15 Oct.
 *
 * @return array{account: Account, organization: Organization, listing: Extension, releases: array, installs: array, sales: array}
 */
function octoberOnAListing(): array
{
    test()->travelTo(Carbon::parse('2026-10-23 12:00:00'));
    [$account, $organization, $listing, [$v1, $v2]] = statsListing();

    $a = statsInstall($listing, $v1, '2026-10-02 10:00:00');
    $a2 = statsUpdate($a, $v2, '2026-10-05 09:00:00');
    $b = statsInstall($listing, $v1, '2026-10-02 15:00:00', ['uninstalled_at' => '2026-10-10 08:00:00']);
    $c = statsInstall($listing, $v2, '2026-10-20 11:00:00');
    $d = statsInstall($listing, $v1, '2026-09-15 09:00:00');
    statsInstall($listing, $v1, '2026-09-20 09:00:00', ['uninstalled_at' => '2026-09-25 09:00:00']);
    // An attempt that failed never counts.
    Install::factory()->failed()->create(['extension_uuid' => $listing->uuid, 'release_uuid' => $v2->uuid, 'started_at' => '2026-10-03 10:00:00', 'finished_at' => '2026-10-03 10:01:00']);

    $buyerA = $a->instance->organization;
    test()->travelTo(Carbon::parse('2026-10-03 12:00:00'));
    $usd = recordedSale($listing, $buyerA);
    test()->travelTo(Carbon::parse('2026-10-04 12:00:00'));
    $refunded = recordedSale($listing, $b->instance->organization, 5292, 490, ['status' => Purchase::STATUS_REFUNDED, 'meta' => ['amount_refunded' => 5292]]);
    test()->travelTo(Carbon::parse('2026-10-12 12:00:00'));
    Event::record('purchase.refunded', $refunded, null, ['amount_refunded' => 5292]);
    test()->travelTo(Carbon::parse('2026-10-15 12:00:00'));
    $eur = recordedSale($listing, $c->instance->organization, 4000, 400, ['currency' => 'EUR']);
    test()->travelTo(Carbon::parse('2026-10-23 12:00:00'));

    return [
        'account' => $account,
        'organization' => $organization,
        'listing' => $listing,
        'releases' => [$v1, $v2],
        'installs' => compact('a', 'a2', 'b', 'c', 'd'),
        'sales' => compact('usd', 'refunded', 'eur'),
    ];
}

it('reports installs, updates, uninstalls, active instances and revenue against the previous period', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);

    $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats?from=2026-10-01&to=2026-10-23")
        ->assertOk()
        ->assertJsonPath('extension.slug', $october['listing']->slug)
        ->assertJsonPath('range', ['from' => '2026-10-01', 'to' => '2026-10-23', 'days' => 23, 'group' => 'day'])
        ->assertJsonPath('previous', ['from' => '2026-09-08', 'to' => '2026-09-30', 'days' => 23])
        ->assertJsonPath('as_of', '2026-10-23T12:00:00+00:00')
        ->assertJsonPath('totals', [
            'installs' => 3,
            'updates' => 1,
            'uninstalls' => 1,
            'purchases' => 3,
            'revenue' => [
                ['currency' => 'USD', 'purchases' => 2, 'gross' => 10584, 'refunded' => 5292, 'fee' => 490, 'net' => 4802],
                ['currency' => 'EUR', 'purchases' => 1, 'gross' => 4000, 'refunded' => 0, 'fee' => 400, 'net' => 3600],
            ],
            'active_instances' => 3,
        ])
        ->assertJsonPath('previous_totals', [
            'installs' => 2,
            'updates' => 0,
            'uninstalls' => 1,
            'purchases' => 0,
            'revenue' => [],
            'active_instances' => 1,
        ]);
});

it('gives a series with every day of the range, zeros included', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);

    $series = $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats?from=2026-10-01&to=2026-10-23")
        ->assertOk()
        ->json('series');

    expect($series)->toHaveCount(23)
        ->and($series[0])->toBe(['date' => '2026-10-01', 'installs' => 0, 'updates' => 0, 'uninstalls' => 0, 'purchases' => 0, 'revenue' => []])
        ->and($series[1])->toMatchArray(['date' => '2026-10-02', 'installs' => 2, 'updates' => 0, 'uninstalls' => 0])
        ->and($series[2]['revenue'])->toBe([['currency' => 'USD', 'purchases' => 1, 'gross' => 5292, 'refunded' => 0, 'fee' => 490, 'net' => 4802]])
        ->and($series[4])->toMatchArray(['date' => '2026-10-05', 'updates' => 1])
        ->and($series[9])->toMatchArray(['date' => '2026-10-10', 'uninstalls' => 1])
        ->and($series[19])->toMatchArray(['date' => '2026-10-20', 'installs' => 1])
        ->and(array_sum(array_column($series, 'installs')))->toBe(3);
});

it('groups the series by Monday-to-Sunday week or by month', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);
    $url = "/v1/publisher/extensions/{$october['listing']->slug}/stats?from=2026-10-01&to=2026-10-23";

    $weeks = $this->getJson("{$url}&group=week")->assertOk()->assertJsonPath('range.group', 'week')->json('series');
    expect(array_column($weeks, 'date'))->toBe(['2026-09-28', '2026-10-05', '2026-10-12', '2026-10-19'])
        ->and(array_column($weeks, 'installs'))->toBe([2, 0, 0, 1])
        ->and(array_column($weeks, 'updates'))->toBe([0, 1, 0, 0])
        ->and(array_column($weeks, 'uninstalls'))->toBe([0, 1, 0, 0])
        ->and(array_column($weeks, 'purchases'))->toBe([2, 0, 1, 0]);

    $months = $this->getJson("{$url}&group=month")->assertOk()->json('series');
    expect($months)->toHaveCount(1)
        ->and($months[0])->toMatchArray(['date' => '2026-10-01', 'installs' => 3, 'updates' => 1, 'uninstalls' => 1, 'purchases' => 3]);
});

it('picks a default range of the last 30 days and a grouping that fits the range', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);
    $url = "/v1/publisher/extensions/{$october['listing']->slug}/stats";

    $this->getJson($url)->assertOk()
        ->assertJsonPath('range', ['from' => '2026-09-24', 'to' => '2026-10-23', 'days' => 30, 'group' => 'day'])
        ->assertJsonCount(30, 'series');
    $this->getJson("{$url}?from=2026-01-01&to=2026-10-23")->assertOk()->assertJsonPath('range.group', 'week');
    $this->getJson("{$url}?from=2025-01-01&to=2026-10-23")->assertOk()->assertJsonPath('range.group', 'month');
});

it('splits active installs by version, newest first', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);
    [$v1, $v2] = $october['releases'];

    $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats")
        ->assertOk()
        ->assertJsonPath('versions', [
            ['release' => $v2->public_id, 'version' => '1.1.0', 'instances' => 2, 'share' => 0.6667],
            ['release' => $v1->public_id, 'version' => '1.0.0', 'instances' => 1, 'share' => 0.3333],
        ]);
});

it('lists recent events newest first with the organization behind each', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);
    ['a' => $a, 'b' => $b, 'c' => $c] = $october['installs'];

    $events = $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats?from=2026-10-01&to=2026-10-23")
        ->assertOk()
        ->assertJsonPath('events.total', 9)
        ->json('events.data');

    expect(array_column($events, 'type'))->toBe(['install', 'purchase', 'refund', 'uninstall', 'update', 'purchase', 'purchase', 'install', 'install'])
        ->and($events[0])->toBe([
            'type' => 'install',
            'at' => '2026-10-20T11:00:00+00:00',
            'extension' => ['id' => $october['listing']->public_id, 'slug' => $october['listing']->slug, 'name' => $october['listing']->name, 'status' => 'published'],
            'organization' => ['id' => $c->instance->organization->public_id, 'slug' => $c->instance->organization->slug, 'name' => $c->instance->organization->name],
            'version' => '1.1.0',
            'from_version' => null,
            'amount' => null,
            'currency' => null,
        ])
        ->and($events[1])->toMatchArray(['amount' => 4000, 'currency' => 'EUR'])
        ->and($events[2])->toMatchArray(['type' => 'refund', 'amount' => 5292, 'currency' => 'USD', 'partial' => false])
        ->and($events[2]['organization']['name'])->toBe($b->instance->organization->name)
        ->and($events[3])->toMatchArray(['type' => 'uninstall', 'version' => '1.0.0', 'at' => '2026-10-10T08:00:00+00:00'])
        ->and($events[4])->toMatchArray(['type' => 'update', 'version' => '1.1.0', 'from_version' => '1.0.0'])
        ->and($events[4]['organization']['name'])->toBe($a->instance->organization->name)
        ->and($events[7]['at'])->toBe('2026-10-02T15:00:00+00:00');
});

it('counts active instances at the end of a past range from the install timestamps', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);

    $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats?from=2026-09-01&to=2026-09-30")
        ->assertOk()
        ->assertJsonPath('totals.installs', 2)
        ->assertJsonPath('totals.uninstalls', 1)
        ->assertJsonPath('totals.active_instances', 1)
        ->assertJsonPath('previous_totals.active_instances', 0);

    // Mid-October: A (updated, still one instance), B (not yet uninstalled) and D.
    $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats?from=2026-10-01&to=2026-10-07")
        ->assertOk()
        ->assertJsonPath('totals.active_instances', 3)
        ->assertJsonPath('previous_totals.active_instances', 1);
});

it('agrees with the catalog install count, which stays derived from installs', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);

    $active = $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats")->assertOk()->json('totals.active_instances');
    $this->getJson('/v1/catalog/extensions?q='.urlencode($october['listing']->name))->assertOk()->assertJsonPath('data.0.installs_count', $active);
    expect(Schema::hasColumn('extensions', 'installs_count'))->toBeFalse();
});

it('records installs, updates and uninstalls reported by instances', function () {
    $this->travelTo(Carbon::parse('2026-10-23 09:00:00'));
    [$account, , $listing, [$v1, $v2]] = statsListing();
    $instance = Instance::factory()->create();
    actingAsInstance($instance);

    $this->postJson('/v1/instances/me/installs', ['extension' => $listing->slug, 'version' => '1.0.0', 'status' => 'installing'])->assertCreated();
    $this->postJson('/v1/instances/me/installs', ['extension' => $listing->slug, 'version' => '1.0.0', 'status' => 'installed'])->assertOk();
    $this->travel(1)->hours();
    $this->postJson('/v1/instances/me/installs', ['extension' => $listing->slug, 'version' => '1.1.0', 'status' => 'installed'])->assertCreated();
    $this->travel(1)->hours();
    $this->deleteJson("/v1/instances/me/installs/{$listing->slug}")->assertOk();

    [$first, $second] = Install::query()->orderBy('id')->get()->all();
    expect($first->installed_at->toDateTimeString())->toBe('2026-10-23 09:00:00')
        ->and($first->replaced_at->toDateTimeString())->toBe('2026-10-23 10:00:00')
        ->and($first->uninstalled_at)->toBeNull()
        ->and($first->previous_release_uuid)->toBeNull()
        ->and($second->previous_release_uuid)->toBe($v1->uuid)
        ->and($second->release_uuid)->toBe($v2->uuid)
        ->and($second->installed_at->toDateTimeString())->toBe('2026-10-23 10:00:00')
        ->and($second->uninstalled_at->toDateTimeString())->toBe('2026-10-23 11:00:00');

    actingAsAccount($account);
    $this->getJson("/v1/publisher/extensions/{$listing->slug}/stats")
        ->assertOk()
        ->assertJsonPath('totals.installs', 1)
        ->assertJsonPath('totals.updates', 1)
        ->assertJsonPath('totals.uninstalls', 1)
        ->assertJsonPath('totals.active_instances', 0)
        ->assertJsonPath('versions', [])
        ->assertJsonPath('events.data.0.type', 'uninstall')
        ->assertJsonPath('events.data.0.version', '1.1.0')
        ->assertJsonPath('events.data.1.type', 'update');
});

it('exports the series as CSV with money columns per currency', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);

    $response = $this->get("/v1/publisher/extensions/{$october['listing']->slug}/stats.csv?from=2026-10-01&to=2026-10-23&group=week")->assertOk();

    expect($response->headers->get('content-disposition'))->toContain("{$october['listing']->slug}-stats-2026-10-01-to-2026-10-23-week.csv")
        ->and(array_map('str_getcsv', explode("\n", trim($response->streamedContent()))))->toBe([
            ['period', 'installs', 'updates', 'uninstalls', 'purchases', 'gross_EUR', 'fee_EUR', 'net_EUR', 'gross_USD', 'fee_USD', 'net_USD'],
            ['2026-09-28', '2', '0', '0', '2', '0.00', '0.00', '0.00', '105.84', '4.90', '48.02'],
            ['2026-10-05', '0', '1', '1', '0', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00'],
            ['2026-10-12', '0', '0', '0', '1', '40.00', '4.00', '36.00', '0.00', '0.00', '0.00'],
            ['2026-10-19', '1', '0', '0', '0', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00'],
        ]);
});

it('caches a report briefly and says when it was built', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);
    $url = "/v1/publisher/extensions/{$october['listing']->slug}/stats";

    $this->getJson($url)->assertJsonPath('totals.installs', 3);
    statsInstall($october['listing'], $october['releases'][1], '2026-10-23 11:00:00');
    $this->travel(30)->seconds();
    $this->getJson($url)->assertJsonPath('totals.installs', 3)->assertJsonPath('as_of', '2026-10-23T12:00:00+00:00');

    $this->travel(31)->seconds();
    $this->getJson($url)->assertJsonPath('totals.installs', 4)->assertJsonPath('as_of', '2026-10-23T12:01:01+00:00');
});

it('runs a bounded number of queries however many installs and sales there are', function () {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);
    config(['registry.analytics_cache_seconds' => 0]);
    $url = "/v1/publisher/extensions/{$october['listing']->slug}/stats?from=2026-10-01&to=2026-10-23";

    $count = function () use ($url) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    // The first request also touches the token's last use; measure warm requests.
    $count();
    $before = $count();
    foreach (range(1, 30) as $day) {
        $at = Carbon::parse('2026-10-01 08:00:00')->addHours($day * 17)->toDateTimeString();
        $install = statsInstall($october['listing'], $october['releases'][$day % 2], $at, $day % 3 === 0 ? ['uninstalled_at' => Carbon::parse($at)->addHour()->toDateTimeString()] : []);
        recordedSale($october['listing'], $install->instance->organization);
    }

    // An eager load with nothing to load is skipped, so more rows can only mean as many queries or fewer.
    expect($count())->toBeLessThanOrEqual($before);
});

it('answers 404 for listings the caller does not publish', function (string $role) {
    $october = octoberOnAListing();
    $outsider = Account::factory()->create();
    $outsider->organizations()->attach($october['organization']->uuid, ['role' => $role]);
    actingAsAccount($outsider);

    $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats")->assertNotFound();
    $this->get("/v1/publisher/extensions/{$october['listing']->slug}/stats.csv")->assertNotFound();
})->with([Membership::ROLE_MEMBER, Membership::ROLE_BILLING]);

it('lets owners, admins and publishers see a listing\'s stats', function (string $role) {
    [$account, , $listing] = statsListing($role);
    actingAsAccount($account);

    $this->getJson("/v1/publisher/extensions/{$listing->slug}/stats")->assertOk()->assertJsonPath('totals.installs', 0);
})->with(Membership::PUBLISHING_ROLES);

it('refuses strangers, tokens without the publish scope and tokens pinned to another organization', function () {
    $october = octoberOnAListing();
    $url = "/v1/publisher/extensions/{$october['listing']->slug}/stats";

    actingAsAccount(Account::factory()->create());
    $this->getJson($url)->assertNotFound();

    actingAsAccount($october['account'], [Token::SCOPE_READ]);
    $this->getJson($url)->assertForbidden();

    $other = Organization::factory()->create();
    $october['account']->organizations()->attach($other->uuid, ['role' => Membership::ROLE_OWNER]);
    $token = Token::issue($october['account'], 'pinned', [Token::SCOPE_ALL], null, $other->uuid);
    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken)->getJson($url)->assertNotFound();

    $this->withHeader('Authorization', '')->getJson($url)->assertUnauthorized();
});

it('validates the range and grouping', function (string $query, string $field) {
    $october = octoberOnAListing();
    actingAsAccount($october['account']);

    $this->getJson("/v1/publisher/extensions/{$october['listing']->slug}/stats?{$query}")
        ->assertUnprocessable()
        ->assertJsonStructure(['errors']);
})->with([
    'unknown group' => ['group=year', 'group'],
    'not a date' => ['from=last-week', 'from'],
    'backwards' => ['from=2026-10-10&to=2026-10-01', 'from'],
    'too long' => ['from=2024-01-01&to=2026-10-23', 'from'],
]);
