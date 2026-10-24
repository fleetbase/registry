<?php

use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Purchase;
use App\Models\Release;

function publishedExtension(array $state = [], ?callable $configure = null): Extension
{
    $factory = Extension::factory()->published()->withPackages()->state($state);
    $factory = $configure ? $configure($factory) : $factory->free();

    return $factory->create();
}

function releaseFor(Extension $extension, string $version, array $state = []): Release
{
    return Release::factory()->published()->create(array_merge([
        'extension_uuid' => $extension->uuid,
        'version' => $version,
        'compat' => [],
    ], $state));
}

function paidLicence(Extension $extension, Instance $instance, int $seats = 5): Licence
{
    $purchase = Purchase::create([
        'extension_uuid' => $extension->uuid,
        'buyer_organization_uuid' => $instance->organization_uuid,
        'amount_total' => 4900,
        'currency' => 'USD',
        'status' => Purchase::STATUS_PAID,
    ]);

    return Licence::create([
        'extension_uuid' => $extension->uuid,
        'organization_uuid' => $instance->organization_uuid,
        'purchase_uuid' => $purchase->uuid,
        'kind' => Licence::KIND_PAID,
        'max_activations' => $seats,
        'status' => Licence::STATUS_ACTIVE,
    ]);
}

it('creates an unlimited free licence and an activation on first install', function () {
    $instance = Instance::factory()->create();
    $extension = publishedExtension();
    releaseFor($extension, '1.0.0');
    actingAsInstance($instance);

    $response = $this->postJson('/v1/instances/me/installs', [
        'extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installing', 'installed_by' => 'console',
    ])->assertCreated()
        ->assertJsonPath('install.status', 'installing')
        ->assertJsonPath('install.release.version', '1.0.0')
        ->assertJsonPath('install.extension.slug', $extension->slug)
        ->assertJsonPath('activation.licence.kind', 'free')
        ->assertJsonPath('activation.licence.max_activations', null)
        ->assertJsonPath('activation.release', '1.0.0');

    $licence = Licence::sole();
    expect($licence->organization_uuid)->toBe($instance->organization_uuid)
        ->and($licence->kind)->toBe(Licence::KIND_FREE)
        ->and($licence->max_activations)->toBeNull()
        ->and($licence->purchase_uuid)->toBeNull();

    $this->postJson('/v1/instances/me/installs', [
        'extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed',
    ])->assertOk()
        ->assertJsonPath('install.id', $response->json('install.id'))
        ->assertJsonPath('install.status', 'installed')
        ->assertJsonPath('activation.id', $response->json('activation.id'));

    expect(Install::sole()->finished_at)->not->toBeNull()
        ->and(Licence::count())->toBe(1)
        ->and(LicenceActivation::whereNull('deactivated_at')->count())->toBe(1);

    // Another instance in the same organization reuses the free licence.
    $sibling = Instance::factory()->create(['organization_uuid' => $instance->organization_uuid]);
    actingAsInstance($sibling);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed'])
        ->assertCreated()->assertJsonPath('activation.licence.id', $licence->public_id);
    expect(Licence::count())->toBe(1);
});

it('resolves extensions by package name and releases by public id', function () {
    $instance = Instance::factory()->create();
    $extension = publishedExtension();
    $release = releaseFor($extension, '2.1.0');
    actingAsInstance($instance);

    $npm = $extension->packages()->where('kind', 'npm')->value('name');

    $this->postJson('/v1/instances/me/installs', ['extension' => $npm, 'release' => $release->public_id, 'status' => 'installed'])
        ->assertCreated()->assertJsonPath('install.release.version', '2.1.0');

    $this->postJson('/v1/instances/me/installs', ['extension' => 'nope', 'version' => '1.0.0', 'status' => 'installed'])->assertStatus(404);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '9.9.9', 'status' => 'installed'])->assertStatus(404);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'status' => 'done'])->assertStatus(422);
});

it('refuses paid extensions without a licence and enforces seats', function () {
    $instance = Instance::factory()->create();
    $extension = publishedExtension([], fn ($f) => $f->paid(4900));
    releaseFor($extension, '1.0.0');
    actingAsInstance($instance);

    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installing'])
        ->assertStatus(403)
        ->assertJsonPath('errors.0', "'{$extension->slug}' is a paid extension and your organization does not hold an active licence for it.");
    expect(Install::count())->toBe(0)->and(Licence::count())->toBe(0);

    $licence = paidLicence($extension, $instance, seats: 1);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed'])
        ->assertCreated()->assertJsonPath('activation.licence.id', $licence->public_id)->assertJsonPath('activation.licence.kind', 'paid');

    $second = Instance::factory()->create(['organization_uuid' => $instance->organization_uuid]);
    actingAsInstance($second);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installing'])
        ->assertStatus(403)->assertJsonPath('errors.0', "All 1 seats for '{$extension->slug}' are in use. Uninstall it from another instance first.");

    // Uninstalling on the first instance frees the seat.
    actingAsInstance($instance);
    $this->deleteJson('/v1/instances/me/installs/'.$extension->slug)->assertOk()->assertJsonPath('deactivated', 1);

    actingAsInstance($second);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installing'])->assertCreated();

    // A revoked licence counts as none.
    $licence->forceFill(['status' => Licence::STATUS_REVOKED])->save();
    $third = Instance::factory()->create(['organization_uuid' => $instance->organization_uuid]);
    actingAsInstance($third);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installing'])->assertStatus(403);
});

it('refuses new installs of unpublished extensions, unpublished releases and other hosting kinds', function () {
    $instance = Instance::factory()->create(['kind' => Instance::KIND_SELF_HOSTED]);
    actingAsInstance($instance);

    $cloudOnly = publishedExtension(['hosting' => Extension::HOSTING_CLOUD]);
    releaseFor($cloudOnly, '1.0.0');
    $this->postJson('/v1/instances/me/installs', ['extension' => $cloudOnly->slug, 'version' => '1.0.0', 'status' => 'installing'])
        ->assertStatus(422)->assertJsonPath('errors.0', 'This extension is only available for cloud instances.');

    $draft = publishedExtension(['status' => Extension::STATUS_DRAFT]);
    releaseFor($draft, '1.0.0');
    $this->postJson('/v1/instances/me/installs', ['extension' => $draft->slug, 'version' => '1.0.0', 'status' => 'installing'])->assertStatus(422);

    $extension = publishedExtension();
    releaseFor($extension, '1.0.0', ['status' => Release::STATUS_YANKED]);
    releaseFor($extension, '1.1.0', ['status' => Release::STATUS_APPROVED, 'published_at' => null]);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installing'])->assertStatus(422);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.1.0', 'status' => 'installed'])->assertStatus(422);

    // A yanked release an instance already has may still be reported as installed.
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed'])->assertCreated();
});

it('supersedes the previous installed row on upgrade so the instance counts once', function () {
    $instance = Instance::factory()->create();
    $extension = publishedExtension();
    releaseFor($extension, '1.0.0');
    releaseFor($extension, '1.1.0');
    actingAsInstance($instance);

    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed'])->assertCreated();
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.1.0', 'status' => 'installing'])->assertCreated();
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.1.0', 'status' => 'installed'])->assertOk();

    expect(Install::where('status', 'installed')->count())->toBe(1)
        ->and(Install::where('status', 'installed')->first()->release->version)->toBe('1.1.0')
        ->and(Install::where('status', 'uninstalled')->count())->toBe(1)
        ->and(LicenceActivation::whereNull('deactivated_at')->sole()->release->version)->toBe('1.1.0');

    $this->getJson('/v1/catalog/extensions?q='.urlencode($extension->name))->assertOk()->assertJsonPath('data.0.installs_count', 1);
});

it('keeps the seat when an upgrade fails and releases it when a first install fails', function () {
    $instance = Instance::factory()->create();
    $extension = publishedExtension();
    releaseFor($extension, '1.0.0');
    releaseFor($extension, '1.1.0');
    actingAsInstance($instance);

    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed']);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.1.0', 'status' => 'installing']);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.1.0', 'status' => 'failed', 'error' => 'composer require failed'])
        ->assertOk()->assertJsonPath('install.status', 'failed')->assertJsonPath('install.error', 'composer require failed')->assertJsonPath('activation', null);

    expect(Install::where('status', 'installed')->sole()->release->version)->toBe('1.0.0')
        ->and(LicenceActivation::whereNull('deactivated_at')->count())->toBe(1);

    $other = publishedExtension();
    releaseFor($other, '1.0.0');
    $this->postJson('/v1/instances/me/installs', ['extension' => $other->slug, 'version' => '1.0.0', 'status' => 'installing']);
    $this->postJson('/v1/instances/me/installs', ['extension' => $other->slug, 'version' => '1.0.0', 'status' => 'failed', 'error' => 'boom']);

    $otherLicence = Licence::where('extension_uuid', $other->uuid)->sole();
    expect($otherLicence->activeActivations()->count())->toBe(0);
});

it('reports uninstalls and deactivates the seat', function () {
    $instance = Instance::factory()->create();
    $extension = publishedExtension();
    releaseFor($extension, '1.0.0');
    actingAsInstance($instance);

    $this->deleteJson('/v1/instances/me/installs/'.$extension->slug)->assertStatus(404);

    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed']);

    $composer = $extension->packages()->where('kind', 'composer')->value('name');
    $this->deleteJson('/v1/instances/me/installs/'.$composer)
        ->assertOk()->assertJsonPath('install.status', 'uninstalled')->assertJsonPath('deactivated', 1);

    expect(Install::sole()->status)->toBe('uninstalled')
        ->and(LicenceActivation::sole()->deactivated_at)->not->toBeNull()
        ->and(Licence::sole()->status)->toBe('active');

    // Reinstalling opens a new activation on the same free licence.
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed'])->assertCreated();
    expect(LicenceActivation::count())->toBe(2)->and(Licence::count())->toBe(1);
});

it('accepts scoped npm package names in the uninstall path', function () {
    $instance = Instance::factory()->create();
    $extension = publishedExtension();
    releaseFor($extension, '1.0.0');
    actingAsInstance($instance);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->slug, 'version' => '1.0.0', 'status' => 'installed']);

    $npm = $extension->packages()->where('kind', 'npm')->value('name');
    expect($npm)->toStartWith('@');

    $this->deleteJson('/v1/instances/me/installs/'.$npm)->assertOk()->assertJsonPath('install.status', 'uninstalled');
});
