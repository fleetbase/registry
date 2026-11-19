<?php

use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Release;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

function compatInstall(Instance $instance, Extension $extension, ?Release $release, string $status = Install::STATUS_INSTALLED): Install
{
    return Install::factory()->create([
        'instance_uuid' => $instance->uuid,
        'extension_uuid' => $extension->uuid,
        'release_uuid' => $release?->uuid,
        'status' => $status,
    ]);
}

function compatRelease(Extension $extension, string $version, array $compat = [], array $state = []): Release
{
    return Release::factory()->published()->create(array_merge([
        'extension_uuid' => $extension->uuid, 'version' => $version, 'compat' => $compat,
    ], $state));
}

/** Adds `extensions.visibility` as the private-extensions work will, so the report can read it. */
function withVisibilityColumn(): void
{
    if (! Schema::hasColumn('extensions', 'visibility')) {
        Schema::table('extensions', fn (Blueprint $table) => $table->string('visibility')->default('public'));
    }
}

it('reports, per installed extension, the newest release that works on a target core version', function () {
    $instance = Instance::factory()->create(['console_version' => '0.7.2', 'api_version' => '1.6.10']);
    actingAsInstance($instance);

    // Installed release keeps working on the target.
    $routes = Extension::factory()->published()->create(['slug' => 'route-planner']);
    $routesInstalled = compatRelease($routes, '2.0.0', ['console' => '>=0.7.0']);
    compatInstall($instance, $routes, $routesInstalled);

    // Installed release stops working; a newer one supports the target (a newer beta and an
    // incompatible stable release are skipped).
    $fleet = Extension::factory()->published()->create(['slug' => 'fleet-insights']);
    $fleetInstalled = compatRelease($fleet, '1.0.0', ['console' => '>=0.7.0 <0.8.0', 'api' => '^1.6']);
    compatRelease($fleet, '1.2.0', ['console' => '>=0.8.0', 'api' => '^2.0']);
    compatRelease($fleet, '1.3.0', ['console' => '>=0.9.0']);
    compatRelease($fleet, '1.4.0-beta.1', ['console' => '>=0.8.0'], ['channel' => Release::CHANNEL_BETA]);
    compatInstall($instance, $fleet, $fleetInstalled);

    // Only an older release supports the target.
    $pallet = Extension::factory()->published()->create(['slug' => 'pallet']);
    compatRelease($pallet, '0.9.0', ['api' => '>=1.0']);
    $palletInstalled = compatRelease($pallet, '1.0.0', ['api' => '<2.0']);
    compatInstall($instance, $pallet, $palletInstalled);

    // Nothing supports the target; a yanked release that would is not offered.
    $legacy = Extension::factory()->published()->create(['slug' => 'legacy-sync']);
    $legacyInstalled = compatRelease($legacy, '0.1.0', ['api' => '<2.0']);
    compatRelease($legacy, '0.2.0', [], ['status' => Release::STATUS_YANKED]);
    compatInstall($instance, $legacy, $legacyInstalled);

    // Not reported: uninstalled rows and other instances' installs.
    $gone = Extension::factory()->published()->create(['slug' => 'gone']);
    compatInstall($instance, $gone, compatRelease($gone, '1.0.0', ['api' => '<2.0']), Install::STATUS_UNINSTALLED);
    compatInstall(Instance::factory()->create(), $legacy, $legacyInstalled);

    $response = $this->getJson('/v1/instances/me/compatibility?console=v0.8.1&api=2.0.2')->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonPath('meta.target', ['console_version' => '0.8.1', 'api_version' => '2.0.2'])
        ->assertJsonPath('meta.instance.console_version', '0.7.2')
        ->assertJsonPath('meta.compatible', false)
        ->assertJsonPath('meta.installed', 4)
        ->assertJsonPath('meta.blocking', 1)
        ->assertJsonPath('meta.blocking_extensions', ['legacy-sync'])
        ->assertJsonPath('meta.updates_required', 2);

    $rows = collect($response->json('data'))->keyBy('extension.slug');

    expect($rows['route-planner'])->toMatchArray(['action' => 'keep', 'installed_compatible' => true, 'compatible_release_exists' => true, 'reason' => null])
        ->and($rows['route-planner']['compatible']['version'])->toBe('2.0.0')
        ->and($rows['fleet-insights'])->toMatchArray(['action' => 'update', 'installed_compatible' => false, 'compatible_release_exists' => true])
        ->and($rows['fleet-insights']['installed']['version'])->toBe('1.0.0')
        ->and($rows['fleet-insights']['compatible']['version'])->toBe('1.2.0')
        ->and($rows['pallet'])->toMatchArray(['action' => 'downgrade', 'installed_compatible' => false])
        ->and($rows['pallet']['compatible']['version'])->toBe('0.9.0')
        ->and($rows['legacy-sync'])->toMatchArray(['action' => 'blocked', 'compatible' => null, 'compatible_release_exists' => false, 'reason' => 'no_compatible_release']);

    // Beta releases count when asked for.
    $beta = collect($this->getJson('/v1/instances/me/compatibility?console=0.8.1&api=2.0.2&channel=beta')->assertOk()->json('data'))->keyBy('extension.slug');
    expect($beta['fleet-insights']['compatible']['version'])->toBe('1.4.0-beta.1');
});

it('is compatible when every installed extension has a release for the target', function () {
    $instance = Instance::factory()->create();
    actingAsInstance($instance);

    $extension = Extension::factory()->published()->create();
    compatInstall($instance, $extension, compatRelease($extension, '1.0.0', ['console' => '^0.7']));
    compatRelease($extension, '1.1.0', ['console' => '^0.8']);

    // Only the console side is given: API ranges do not constrain.
    $this->getJson('/v1/instances/me/compatibility?console=0.8.0')->assertOk()
        ->assertJsonPath('meta.compatible', true)
        ->assertJsonPath('meta.blocking', 0)
        ->assertJsonPath('meta.target', ['console_version' => '0.8.0'])
        ->assertJsonPath('data.0.action', 'update')
        ->assertJsonPath('data.0.compatible.version', '1.1.0');

    // No installs: trivially compatible.
    actingAsInstance(Instance::factory()->create());
    $this->getJson('/v1/instances/me/compatibility?console=0.8.0')->assertOk()
        ->assertJsonPath('data', [])
        ->assertJsonPath('meta.compatible', true);
});

it('checks the prebuilt dist of the chosen release against the target host modules', function () {
    config(['registry.storage.disk' => 'packages']);
    Storage::fake('packages');

    $instance = Instance::factory()->create();
    actingAsInstance($instance);

    $extension = Extension::factory()->published()->create();
    compatInstall($instance, $extension, compatRelease($extension, '1.0.0', ['console' => '^0.7']));
    $next = compatRelease($extension, '1.1.0', ['console' => '^0.8']);
    attachDist($next, '@acme/fleet-insights-engine', ['@fleetbase/ember-ui' => '0.4.7', '@fleetbase/ember-core' => '0.3.27']);

    $fits = '/v1/instances/me/compatibility?console=0.8.1&host[@fleetbase/ember-ui]=0.4.9&host[@fleetbase/ember-core]=0.3.30';
    $this->getJson($fits)->assertOk()
        ->assertJsonPath('meta.target.policy', 'minor')
        ->assertJsonPath('data.0.dist.compatible', true)
        ->assertJsonPath('data.0.dist.built_against.@fleetbase/ember-core', '0.3.27');

    // A minor bump of ember-core does not fit under `minor`, does under `major`; it never blocks.
    $bumped = '/v1/instances/me/compatibility?console=0.8.1&host[@fleetbase/ember-ui]=0.4.9&host[@fleetbase/ember-core]=0.4.0';
    $this->getJson($bumped)->assertOk()
        ->assertJsonPath('data.0.dist.compatible', false)
        ->assertJsonPath('data.0.dist.reasons', ['@fleetbase/ember-core 0.3.27 (built against) is not compatible with 0.4.0 (console)'])
        ->assertJsonPath('data.0.action', 'update')
        ->assertJsonPath('meta.compatible', true);
    $this->getJson($bumped.'&policy=major')->assertOk()->assertJsonPath('data.0.dist.compatible', true);

    // Without host versions there is nothing to compare.
    $this->getJson('/v1/instances/me/compatibility?console=0.8.1')->assertOk()->assertJsonPath('data.0.dist', null);
});

it('validates the target versions', function () {
    actingAsInstance(Instance::factory()->create());

    $this->getJson('/v1/instances/me/compatibility')->assertStatus(422)->assertJsonValidationErrors(['console', 'api'], 'fields');
    $this->getJson('/v1/instances/me/compatibility?console=latest')->assertStatus(422)->assertJsonValidationErrors(['console'], 'fields');
    $this->getJson('/v1/instances/me/compatibility?api=dev-main')->assertStatus(422)->assertJsonValidationErrors(['api'], 'fields');
    $this->getJson('/v1/instances/me/compatibility?console=0.8.1&channel=nightly')->assertStatus(422)->assertJsonValidationErrors(['channel'], 'fields');
    $this->getJson('/v1/instances/me/compatibility?console=0.8.1&policy=loose')->assertStatus(422)->assertJsonValidationErrors(['policy'], 'fields');
    $this->getJson('/v1/instances/me/compatibility?console=0.8.1&host[@fleetbase/ember-core]=next')->assertStatus(422);
    $this->getJson('/v1/instances/me/compatibility?console=0.8')->assertOk()->assertJsonPath('meta.target.console_version', '0.8.0');
});

it('requires an instance token, or staff for any instance', function () {
    $instance = Instance::factory()->create();
    $extension = Extension::factory()->published()->create(['slug' => 'fleet-insights']);
    compatInstall($instance, $extension, compatRelease($extension, '1.0.0', ['console' => '^0.7']));

    $this->getJson('/v1/instances/me/compatibility?console=0.8.0')->assertStatus(401);
    $this->getJson("/v1/admin/instances/{$instance->public_id}/compatibility?console=0.8.0")->assertStatus(401);

    // A publisher's personal token is not an instance token, and not staff.
    [$account] = publisher();
    actingAsAccount($account);
    $this->getJson('/v1/instances/me/compatibility?console=0.8.0')->assertStatus(401);
    $this->getJson("/v1/admin/instances/{$instance->public_id}/compatibility?console=0.8.0")->assertStatus(403);

    // An instance token does not reach the admin route.
    $this->flushHeaders();
    actingAsInstance($instance);
    $this->getJson("/v1/admin/instances/{$instance->public_id}/compatibility?console=0.8.0")->assertStatus(401);

    $this->flushHeaders();
    actingAsAccount(staffAccount());
    $this->getJson("/v1/admin/instances/{$instance->public_id}/compatibility?console=0.8.0")->assertOk()
        ->assertJsonPath('meta.instance.id', $instance->public_id)
        ->assertJsonPath('meta.blocking_extensions', ['fleet-insights']);
    $this->getJson('/v1/admin/instances/inst_missing/compatibility?console=0.8.0')->assertStatus(404);
});

it('describes private extensions only to instances that may see them', function () {
    withVisibilityColumn();

    $instance = Instance::factory()->create();
    $private = function (array $attributes): Extension {
        $extension = Extension::factory()->published()->create($attributes);
        $extension->forceFill(['visibility' => 'private'])->save();

        return $extension;
    };
    $own = $private(['slug' => 'own-private', 'organization_uuid' => $instance->organization_uuid]);
    $granted = $private(['slug' => 'granted-private']);
    $revoked = $private(['slug' => 'revoked-private', 'name' => 'Acme Secret Sync']);
    $public = Extension::factory()->published()->create(['slug' => 'public-one']);

    foreach ([$own, $granted, $revoked, $public] as $extension) {
        compatInstall($instance, $extension, compatRelease($extension, '1.0.0', ['console' => '^0.8']));
        compatRelease($extension, '1.1.0', ['console' => '^0.8']);
    }
    Licence::create(['extension_uuid' => $granted->uuid, 'organization_uuid' => $instance->organization_uuid, 'kind' => Licence::KIND_FREE, 'status' => Licence::STATUS_ACTIVE]);
    Licence::create(['extension_uuid' => $revoked->uuid, 'organization_uuid' => $instance->organization_uuid, 'kind' => Licence::KIND_FREE, 'status' => Licence::STATUS_REVOKED]);

    actingAsInstance($instance);
    $response = $this->getJson('/v1/instances/me/compatibility?console=0.8.1')->assertOk()
        ->assertJsonPath('meta.blocking_extensions', ['revoked-private']);
    $rows = collect($response->json('data'))->keyBy('extension.slug');

    expect($rows['own-private']['compatible']['version'])->toBe('1.1.0')
        ->and($rows['granted-private']['compatible']['version'])->toBe('1.1.0')
        ->and($rows['public-one']['compatible']['version'])->toBe('1.1.0')
        ->and($rows['revoked-private'])->toMatchArray(['action' => 'blocked', 'reason' => 'no_access', 'installed' => null, 'compatible' => null])
        ->and($rows['revoked-private']['extension'])->toBe(['id' => $revoked->public_id, 'slug' => 'revoked-private', 'name' => null])
        ->and(json_encode($response->json()))->not->toContain('Acme Secret Sync');

    // Staff see everything.
    $this->flushHeaders();
    actingAsAccount(staffAccount());
    $staff = collect($this->getJson("/v1/admin/instances/{$instance->public_id}/compatibility?console=0.8.1")->assertOk()->json('data'))->keyBy('extension.slug');
    expect($staff['revoked-private']['compatible']['version'])->toBe('1.1.0')
        ->and($staff['revoked-private']['extension']['name'])->toBe('Acme Secret Sync');
});
