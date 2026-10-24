<?php

use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Release;

function installOn(Instance $instance, Extension $extension, ?Release $release, string $status = Install::STATUS_INSTALLED): Install
{
    return Install::factory()->create([
        'instance_uuid' => $instance->uuid,
        'extension_uuid' => $extension->uuid,
        'release_uuid' => $release?->uuid,
        'status' => $status,
    ]);
}

function release(Extension $extension, string $version, array $compat = [], array $state = []): Release
{
    return Release::factory()->published()->create(array_merge([
        'extension_uuid' => $extension->uuid, 'version' => $version, 'compat' => $compat,
    ], $state));
}

it('returns the instance, its organization and licences with seat usage', function () {
    $instance = Instance::factory()->create();
    $sibling = Instance::factory()->create(['organization_uuid' => $instance->organization_uuid]);
    $extension = Extension::factory()->published()->create();
    $other = Extension::factory()->published()->create();

    $licence = Licence::create([
        'extension_uuid' => $extension->uuid, 'organization_uuid' => $instance->organization_uuid,
        'kind' => Licence::KIND_PAID, 'max_activations' => 5, 'status' => Licence::STATUS_ACTIVE,
    ]);
    LicenceActivation::create(['licence_uuid' => $licence->uuid, 'instance_uuid' => $instance->uuid, 'activated_at' => now()]);
    LicenceActivation::create(['licence_uuid' => $licence->uuid, 'instance_uuid' => $sibling->uuid, 'activated_at' => now()]);
    LicenceActivation::create(['licence_uuid' => $licence->uuid, 'instance_uuid' => $sibling->uuid, 'activated_at' => now()->subDay(), 'deactivated_at' => now()]);

    Licence::create([
        'extension_uuid' => $other->uuid, 'organization_uuid' => $instance->organization_uuid,
        'kind' => Licence::KIND_FREE, 'max_activations' => null, 'status' => Licence::STATUS_ACTIVE,
    ]);
    Licence::create([
        'extension_uuid' => $other->uuid, 'organization_uuid' => Instance::factory()->create()->organization_uuid,
        'kind' => Licence::KIND_FREE, 'max_activations' => null, 'status' => Licence::STATUS_ACTIVE,
    ]);

    actingAsInstance($instance);
    $response = $this->getJson('/v1/instances/me')->assertOk()
        ->assertJsonPath('instance.id', $instance->public_id)
        ->assertJsonPath('instance.organization.id', $instance->organization->public_id)
        ->assertJsonCount(2, 'licences');

    $licences = collect($response->json('licences'))->keyBy('extension.slug');
    expect($licences[$extension->slug])->toMatchArray(['kind' => 'paid', 'max_activations' => 5, 'seats_used' => 2, 'activated_here' => true])
        ->and($licences[$other->slug])->toMatchArray(['kind' => 'free', 'max_activations' => null, 'seats_used' => 0, 'activated_here' => false]);

    expect($instance->fresh()->last_seen_at)->not->toBeNull();
    expect(json_encode($response->json()))->not->toContain('token');
});

it('lists installed releases against the newest compatible published release', function () {
    $instance = Instance::factory()->create(['console_version' => '0.7.2', 'api_version' => '1.6.10']);
    actingAsInstance($instance);

    // Newer compatible release available; a newer incompatible one and a beta are skipped.
    $fleet = Extension::factory()->published()->create(['slug' => 'fleet-insights']);
    $installed = release($fleet, '1.0.0', ['console' => '>=0.6.0']);
    release($fleet, '1.2.0', ['console' => '>=0.7.0', 'api' => '^1.6']);
    release($fleet, '1.10.0', ['console' => '>=0.8.0']);
    release($fleet, '1.11.0-beta.1', [], ['channel' => Release::CHANNEL_BETA]);
    release($fleet, '1.9.0', [], ['status' => Release::STATUS_YANKED]);
    installOn($instance, $fleet, $installed);

    // Already on the latest.
    $routes = Extension::factory()->published()->create(['slug' => 'route-planner']);
    $current = release($routes, '2.0.0');
    installOn($instance, $routes, $current);

    // Nothing compatible at all.
    $legacy = Extension::factory()->published()->create(['slug' => 'legacy-sync']);
    $old = release($legacy, '0.1.0', ['api' => '<1.0']);
    installOn($instance, $legacy, $old);

    // Not reported: uninstalled and failed rows, and other instances' installs.
    $gone = Extension::factory()->published()->create(['slug' => 'gone']);
    installOn($instance, $gone, release($gone, '1.0.0'), Install::STATUS_UNINSTALLED);
    installOn(Instance::factory()->create(), $routes, $current);

    $response = $this->getJson('/v1/instances/me/updates')->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('meta.updates_available', 1)
        ->assertJsonPath('meta.console_version', '0.7.2');

    $rows = collect($response->json('data'))->keyBy('extension.slug');

    expect($rows['fleet-insights']['installed']['version'])->toBe('1.0.0')
        ->and($rows['fleet-insights']['latest']['version'])->toBe('1.2.0')
        ->and($rows['fleet-insights']['update_available'])->toBeTrue()
        ->and($rows['route-planner']['latest']['version'])->toBe('2.0.0')
        ->and($rows['route-planner']['update_available'])->toBeFalse()
        ->and($rows['legacy-sync']['installed']['version'])->toBe('0.1.0')
        ->and($rows['legacy-sync']['latest'])->toBeNull()
        ->and($rows['legacy-sync']['update_available'])->toBeFalse();

    $beta = collect($this->getJson('/v1/instances/me/updates?channel=beta')->assertOk()->json('data'))->keyBy('extension.slug');
    expect($beta['fleet-insights']['latest']['version'])->toBe('1.11.0-beta.1');
});

it('does not constrain on versions the instance does not report', function () {
    $instance = Instance::factory()->create(['console_version' => null, 'api_version' => 'dev-main']);
    actingAsInstance($instance);

    $extension = Extension::factory()->published()->create();
    installOn($instance, $extension, release($extension, '1.0.0'));
    release($extension, '1.1.0', ['console' => '>=99.0', 'api' => '>=99.0']);
    release($extension, '1.2.0', ['console' => 'not a range']);

    $this->getJson('/v1/instances/me/updates')->assertOk()
        ->assertJsonPath('data.0.latest.version', '1.1.0')
        ->assertJsonPath('data.0.update_available', true);
});

it('requires an instance token', function () {
    $this->getJson('/v1/instances/me')->assertStatus(401);
    $this->getJson('/v1/instances/me/updates')->assertStatus(401);
    $this->postJson('/v1/instances/me/installs', [])->assertStatus(401);
    $this->deleteJson('/v1/instances/me/installs/x')->assertStatus(401);
});
