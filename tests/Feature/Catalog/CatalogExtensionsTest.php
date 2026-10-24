<?php

use App\Models\Category;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Organization;
use App\Models\Release;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

function catalogExtension(array $attributes = [], array $options = []): Extension
{
    $factory = Extension::factory()->published()->withPackages();
    $factory = ($options['paid'] ?? false) ? $factory->paid($options['amount'] ?? 4900) : $factory->free();
    $factory = $factory->withPublishedRelease($options['version'] ?? '1.0.0');

    return $factory->create($attributes);
}

it('lists published extensions with publisher, category, latest release, installs and price', function () {
    $publisher = Organization::factory()->verified()->create(['slug' => 'acme']);
    $category = Category::factory()->create(['slug' => 'telematics']);
    $extension = catalogExtension(['organization_uuid' => $publisher->uuid, 'category_uuid' => $category->uuid, 'slug' => 'acme-tracker'], ['paid' => true, 'version' => '2.1.0']);
    Install::factory()->count(3)->create(['extension_uuid' => $extension->uuid]);
    Install::factory()->failed()->create(['extension_uuid' => $extension->uuid]);
    Extension::factory()->create(['status' => Extension::STATUS_DRAFT]);
    Extension::factory()->create(['status' => Extension::STATUS_SUSPENDED]);

    $response = $this->getJson('/v1/catalog/extensions')->assertOk();

    $response->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $extension->public_id)
        ->assertJsonPath('data.0.slug', 'acme-tracker')
        ->assertJsonPath('data.0.publisher.slug', 'acme')
        ->assertJsonPath('data.0.publisher.is_verified', true)
        ->assertJsonPath('data.0.category.slug', 'telematics')
        ->assertJsonPath('data.0.latest_version', '2.1.0')
        ->assertJsonPath('data.0.latest_release.version', '2.1.0')
        ->assertJsonPath('data.0.compat.console', '>=0.5.0')
        ->assertJsonPath('data.0.installs_count', 3)
        ->assertJsonPath('data.0.price.model', 'one_time')
        ->assertJsonPath('data.0.price.amount', 4900)
        ->assertJsonPath('data.0.is_paid', true)
        ->assertJsonPath('data.0.packages.npm', '@acme/acme-tracker-engine')
        ->assertJsonPath('data.0.packages.composer', 'acme/acme-tracker-api')
        ->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page', 'from', 'to', 'time']])
        ->assertJsonMissingPath('data.0.uuid');

    expect($response->json('data.0.latest_release.id'))->toStartWith('rel_');
    expect($response->headers->get('Cache-Control'))->toContain('max-age=60');
});

it('searches by q and filters by category, pricing and hosting', function () {
    $telematics = Category::factory()->create(['slug' => 'telematics']);
    $accounting = Category::factory()->create(['slug' => 'accounting']);

    $gps = catalogExtension(['name' => 'GPS Tracker', 'slug' => 'gps-tracker', 'category_uuid' => $telematics->uuid, 'hosting' => 'both']);
    $books = catalogExtension(['name' => 'Quick Books', 'slug' => 'quick-books', 'category_uuid' => $accounting->uuid, 'hosting' => 'cloud'], ['paid' => true]);
    $routes = catalogExtension(['name' => 'Route Planner', 'slug' => 'route-planner', 'subtitle' => 'tracker for routes', 'category_uuid' => $telematics->uuid, 'hosting' => 'self_hosted']);

    $slugs = fn (string $query) => collect($this->getJson('/v1/catalog/extensions?'.$query)->assertOk()->json('data'))->pluck('slug')->sort()->values()->all();

    expect($slugs('q=tracker'))->toBe(['gps-tracker', 'route-planner'])
        ->and($slugs('category=telematics'))->toBe(['gps-tracker', 'route-planner'])
        ->and($slugs('category='.$accounting->public_id))->toBe(['quick-books'])
        ->and($slugs('pricing=paid'))->toBe(['quick-books'])
        ->and($slugs('pricing=free'))->toBe(['gps-tracker', 'route-planner'])
        ->and($slugs('hosting=cloud'))->toBe(['gps-tracker', 'quick-books'])
        ->and($slugs('hosting=self_hosted'))->toBe(['gps-tracker', 'route-planner'])
        ->and($slugs('hosting=both'))->toBe(['gps-tracker'])
        ->and($slugs('q=tracker&category=telematics&pricing=free&hosting=self_hosted'))->toBe(['gps-tracker', 'route-planner']);
});

it('sorts by popular, newest, name and price and paginates', function () {
    $old = catalogExtension(['name' => 'Bravo', 'slug' => 'bravo', 'published_at' => now()->subDays(10)], ['paid' => true, 'amount' => 9900]);
    $new = catalogExtension(['name' => 'Alpha', 'slug' => 'alpha', 'published_at' => now()->subDay()]);
    $popular = catalogExtension(['name' => 'Charlie', 'slug' => 'charlie', 'published_at' => now()->subDays(5)], ['paid' => true, 'amount' => 1000]);
    Install::factory()->count(5)->create(['extension_uuid' => $popular->uuid]);
    Install::factory()->count(2)->create(['extension_uuid' => $old->uuid]);

    $slugs = fn (string $query) => collect($this->getJson('/v1/catalog/extensions?'.$query)->assertOk()->json('data'))->pluck('slug')->all();

    expect($slugs('sort=popular'))->toBe(['charlie', 'bravo', 'alpha'])
        ->and($slugs(''))->toBe(['charlie', 'bravo', 'alpha'])
        ->and($slugs('sort=newest'))->toBe(['alpha', 'charlie', 'bravo'])
        ->and($slugs('sort=name'))->toBe(['alpha', 'bravo', 'charlie'])
        ->and($slugs('sort=price'))->toBe(['alpha', 'charlie', 'bravo']);

    $page = $this->getJson('/v1/catalog/extensions?sort=name&limit=2&page=2')->assertOk();
    $page->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.slug', 'charlie')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.current_page', 2)
        ->assertJsonPath('meta.last_page', 2);
});

it('caches catalog pages for 60 seconds', function () {
    catalogExtension(['slug' => 'first']);

    $this->getJson('/v1/catalog/extensions')->assertOk()->assertJsonCount(1, 'data');

    catalogExtension(['slug' => 'second']);
    $this->getJson('/v1/catalog/extensions')->assertOk()->assertJsonCount(1, 'data');
    $this->getJson('/v1/catalog/extensions?sort=name')->assertOk()->assertJsonCount(2, 'data');

    $this->travel(61)->seconds();
    $this->getJson('/v1/catalog/extensions')->assertOk()->assertJsonCount(2, 'data');

    Cache::flush();
});

it('shows one extension with description, links, versions and screenshots', function () {
    $publisher = Organization::factory()->create(['slug' => 'acme']);
    $extension = catalogExtension(['organization_uuid' => $publisher->uuid, 'slug' => 'acme-tracker', 'description' => 'Tracks things.', 'repo_url' => 'https://github.com/acme/tracker'], ['version' => '1.0.0']);
    Release::factory()->published()->create(['extension_uuid' => $extension->uuid, 'version' => '1.1.0', 'published_at' => now()->addMinute()]);
    Release::factory()->published()->beta()->create(['extension_uuid' => $extension->uuid, 'version' => '2.0.0-beta.1', 'published_at' => now()->addMinutes(2)]);
    Release::factory()->create(['extension_uuid' => $extension->uuid, 'version' => '3.0.0', 'status' => Release::STATUS_IN_REVIEW]);
    $extension->screenshots()->create(['type' => 'screenshot', 'disk' => 'local', 'path' => 'screenshots/one.png', 'name' => 'one.png', 'mime' => 'image/png', 'size' => 10]);

    $this->getJson('/v1/catalog/extensions/acme-tracker')
        ->assertOk()
        ->assertJsonPath('id', $extension->public_id)
        ->assertJsonPath('description', 'Tracks things.')
        ->assertJsonPath('repo_url', 'https://github.com/acme/tracker')
        ->assertJsonPath('publisher.slug', 'acme')
        ->assertJsonPath('latest_version', '2.0.0-beta.1')
        ->assertJsonPath('versions_count', 3)
        ->assertJsonCount(3, 'versions')
        ->assertJsonCount(1, 'screenshots')
        ->assertJsonPath('screenshots.0.name', 'one.png');

    $this->getJson('/v1/catalog/extensions/'.$extension->public_id)->assertOk()->assertJsonPath('slug', 'acme-tracker');
});

it('carries the prebuilt dist size, sha256 and builtAgainst on catalog releases', function () {
    $extension = catalogExtension(['slug' => 'acme-tracker'], ['version' => '1.0.0']);
    $engine = $extension->packages()->where('kind', 'npm')->value('name');
    $latest = Release::factory()->published()->create(['extension_uuid' => $extension->uuid, 'version' => '1.1.0', 'published_at' => now()->addMinute()]);
    $file = attachDist($latest, $engine, ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/ember-core' => '0.3.26']);
    $dist = ['size' => (int) $file->size, 'sha256' => $file->checksum, 'integrity' => $file->integrity, 'built_against' => ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/ember-core' => '0.3.26']];

    $this->getJson('/v1/catalog/extensions')->assertOk()->assertJsonPath('data.0.latest_release.dist', $dist);

    $detail = $this->getJson('/v1/catalog/extensions/acme-tracker')->assertOk()->assertJsonPath('latest_release.dist', $dist);
    $versions = collect($detail->json('versions'))->keyBy('version');
    expect($versions['1.1.0']['dist'])->toBe($dist)
        ->and($versions['1.0.0']['dist'])->toBeNull();

    $releases = collect($this->getJson('/v1/catalog/extensions/acme-tracker/releases')->assertOk()->json('releases'))->keyBy('version');
    expect($releases['1.1.0']['dist'])->toBe($dist)
        ->and($releases['1.0.0']['dist'])->toBeNull();

    Cache::flush();
});

it('returns 404 for unknown or unpublished extensions', function () {
    Extension::factory()->create(['slug' => 'draft-only']);

    $this->getJson('/v1/catalog/extensions/missing')->assertStatus(404)->assertExactJson(['errors' => ['Resource not found.']]);
    $this->getJson('/v1/catalog/extensions/draft-only')->assertStatus(404);
});

it('lists releases with channels, dates, sizes, notes and compat, including yanked ones', function () {
    $extension = catalogExtension(['slug' => 'acme-tracker'], ['version' => '1.0.0']);
    Release::factory()->published()->create(['extension_uuid' => $extension->uuid, 'version' => '1.1.0', 'published_at' => now()->addMinute(), 'notes' => 'Fixes', 'size_bytes' => 1234]);
    Release::factory()->published()->beta()->create(['extension_uuid' => $extension->uuid, 'version' => '1.2.0-beta.1', 'published_at' => now()->addMinutes(2)]);
    Release::factory()->yanked()->create(['extension_uuid' => $extension->uuid, 'version' => '0.9.0']);
    Release::factory()->create(['extension_uuid' => $extension->uuid, 'version' => '9.9.9', 'status' => Release::STATUS_UPLOADED]);

    $response = $this->getJson('/v1/catalog/extensions/acme-tracker/releases')->assertOk();

    $response->assertJsonPath('extension', 'acme-tracker')
        ->assertJsonPath('latest', '1.1.0')
        ->assertJsonPath('channels.stable', '1.1.0')
        ->assertJsonPath('channels.beta', '1.2.0-beta.1')
        ->assertJsonCount(4, 'releases');

    $versions = collect($response->json('releases'))->pluck('version')->all();
    expect($versions)->toContain('0.9.0')->not->toContain('9.9.9');

    $fixes = collect($response->json('releases'))->firstWhere('version', '1.1.0');
    expect($fixes['notes'])->toBe('Fixes')
        ->and($fixes['size_bytes'])->toBe(1234)
        ->and($fixes['channel'])->toBe('stable')
        ->and($fixes['compat'])->toHaveKeys(['console', 'api']);

    $this->getJson('/v1/catalog/extensions/acme-tracker/releases?channel=beta')->assertOk()->assertJsonCount(1, 'releases');
});

it('filters and annotates listings by console compatibility', function () {
    config(['registry.storage.disk' => 'packages']);
    Storage::fake('packages');

    $current = makePackage('npm', '@acme/current-engine', extension: ['name' => 'Current', 'slug' => 'current']);
    makeRelease($current, '2.0.0', publishedAt: now()->toDateTimeString())->forceFill(['compat' => ['console' => '>=0.7.60']])->save();
    // An older release still works with older consoles: the console would install it.
    $older = makePackage('npm', '@acme/older-engine', extension: ['name' => 'Older', 'slug' => 'older']);
    makeRelease($older, '1.0.0', publishedAt: now()->subDays(5)->toDateTimeString())->forceFill(['compat' => ['console' => '>=0.7.0']])->save();
    makeRelease($older, '2.0.0', publishedAt: now()->toDateTimeString())->forceFill(['compat' => ['console' => '>=0.8.0']])->save();
    $legacy = makePackage('npm', '@acme/legacy-engine', extension: ['name' => 'Legacy', 'slug' => 'legacy']);
    makeRelease($legacy, '1.0.0')->forceFill(['compat' => ['console' => '<0.7.0']])->save();
    // Unpublished releases never count.
    makeRelease($legacy, '1.1.0', status: Release::STATUS_SUBMITTED)->forceFill(['compat' => ['console' => '>=0.7.0']])->save();

    $this->getJson('/v1/catalog/extensions?sort=name&console=0.7.69&compatible=1')
        ->assertOk()
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('data.0.slug', 'current')
        ->assertJsonPath('data.0.compatibility', ['console' => '0.7.69', 'compatible' => true, 'newest_compatible' => '2.0.0'])
        ->assertJsonPath('data.1.slug', 'older')
        ->assertJsonPath('data.1.compatibility', ['console' => '0.7.69', 'compatible' => false, 'newest_compatible' => '1.0.0']);

    // Without compatible=1 every listing is annotated and none is dropped.
    $this->getJson('/v1/catalog/extensions?sort=name&console=0.7.69')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.1.slug', 'legacy')
        ->assertJsonPath('data.1.compatibility.newest_compatible', null);

    // No (or an invalid) console version: no filter and no annotation.
    $this->getJson('/v1/catalog/extensions?compatible=1')->assertJsonPath('meta.total', 3)->assertJsonMissingPath('data.0.compatibility');
    $this->getJson('/v1/catalog/extensions?compatible=1&console=latest')->assertJsonPath('meta.total', 3)->assertJsonMissingPath('data.0.compatibility');
});
