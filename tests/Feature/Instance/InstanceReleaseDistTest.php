<?php

use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Release;

function distLicence(Extension $extension, Instance $instance, array $state = []): Licence
{
    return Licence::create(array_merge([
        'extension_uuid' => $extension->uuid, 'organization_uuid' => $instance->organization_uuid,
        'kind' => Licence::KIND_PAID, 'max_activations' => 5, 'status' => Licence::STATUS_ACTIVE,
    ], $state));
}

it('streams the prebuilt dist of a free release to any instance, verified by its sha256', function () {
    $package = makePackage(ExtensionPackage::KIND_NPM, '@acme/dock-notes-engine');
    $release = makeRelease($package, '1.0.0');
    $file = attachDist($release, $package->name);
    actingAsInstance(Instance::factory()->create());

    $response = $this->get('/v1/instances/me/releases/'.$release->public_id.'/dist')->assertOk()
        ->assertHeader('X-Checksum-Sha256', $file->checksum)
        ->assertHeader('ETag', '"'.$file->checksum.'"');

    expect(hash('sha256', $response->streamedContent()))->toBe($file->checksum)
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable');

    $this->withHeader('If-None-Match', '"'.$file->checksum.'"')
        ->get('/v1/instances/me/releases/'.$release->public_id.'/dist')->assertStatus(304);
});

it('names the download in dist.url on update releases, with size, sha256 and builtAgainst', function () {
    $package = makePackage(ExtensionPackage::KIND_NPM, '@acme/dock-notes-engine');
    $installed = makeRelease($package, '1.0.0');
    $latest = makeRelease($package, '1.1.0', publishedAt: now()->addMinute()->toDateTimeString());
    $file = attachDist($latest, $package->name, ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/ember-core' => '0.4.0']);
    $instance = Instance::factory()->create();
    Install::factory()->create(['instance_uuid' => $instance->uuid, 'extension_uuid' => $package->extension_uuid, 'release_uuid' => $installed->uuid, 'status' => Install::STATUS_INSTALLED]);
    actingAsInstance($instance);

    $row = $this->getJson('/v1/instances/me/updates')->assertOk()->json('data.0');

    expect($row['installed']['dist'])->toBeNull()
        ->and($row['latest']['version'])->toBe('1.1.0')
        ->and($row['latest']['dist'])->toBe([
            'url' => 'http://localhost/v1/instances/me/releases/'.$latest->public_id.'/dist',
            'size' => (int) $file->size,
            'sha256' => $file->checksum,
            'integrity' => $file->integrity,
            'built_against' => ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/ember-core' => '0.4.0'],
        ]);

    $download = $this->get($row['latest']['dist']['url'])->assertOk();
    expect(hash('sha256', $download->streamedContent()))->toBe($row['latest']['dist']['sha256']);
});

it('serves a paid dist only to instances whose organization holds an active licence or owns the extension', function () {
    $package = makePackage(ExtensionPackage::KIND_NPM, '@acme/pro-engine', paid: true);
    $extension = $package->extension;
    $release = makeRelease($package, '1.0.0');
    attachDist($release, $package->name);
    $url = '/v1/instances/me/releases/'.$release->public_id.'/dist';

    $instance = Instance::factory()->create();
    actingAsInstance($instance);
    $refused = $this->getJson($url)->assertForbidden()
        ->assertJsonPath('errors.0', "Your organization does not hold an active licence for {$extension->name}.");
    expect($refused->headers->get('Cache-Control'))->toContain('no-store');

    $licence = distLicence($extension, $instance);
    $response = $this->get($url)->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-cache')
        ->and($response->headers->get('Cache-Control'))->not->toContain('immutable');

    $licence->forceFill(['status' => Licence::STATUS_REVOKED])->save();
    $this->getJson($url)->assertForbidden();

    $licence->forceFill(['status' => Licence::STATUS_ACTIVE, 'expires_at' => now()->subDay()])->save();
    $this->getJson($url)->assertForbidden();

    // A licence held by another organization does not count; the publisher's own instance does.
    distLicence($extension, Instance::factory()->create());
    $this->getJson($url)->assertForbidden();

    actingAsInstance(Instance::factory()->create(['organization_uuid' => $extension->organization_uuid]));
    $this->get($url)->assertOk();
});

it('keeps yanked dists downloadable and 404s unreleased, dist-less, suspended and unknown releases', function () {
    $package = makePackage(ExtensionPackage::KIND_NPM, '@acme/dock-notes-engine');
    $yanked = makeRelease($package, '1.0.0', status: Release::STATUS_YANKED);
    attachDist($yanked, $package->name);
    $plain = makeRelease($package, '1.1.0');
    $review = makeRelease($package, '2.0.0', status: Release::STATUS_IN_REVIEW);
    attachDist($review, $package->name);
    actingAsInstance(Instance::factory()->create());

    $this->get('/v1/instances/me/releases/'.$yanked->public_id.'/dist')->assertOk();
    $this->getJson('/v1/instances/me/releases/'.$plain->public_id.'/dist')->assertNotFound()->assertJsonPath('errors.0', 'This release has no prebuilt dist.');
    $this->getJson('/v1/instances/me/releases/'.$review->public_id.'/dist')->assertNotFound()->assertJsonPath('errors.0', 'Release not found.');
    $this->getJson('/v1/instances/me/releases/rel_missing/dist')->assertNotFound();

    $package->extension->forceFill(['status' => Extension::STATUS_SUSPENDED])->save();
    $this->getJson('/v1/instances/me/releases/'.$yanked->public_id.'/dist')->assertNotFound();
});

it('requires an active instance token', function () {
    $package = makePackage(ExtensionPackage::KIND_NPM, '@acme/dock-notes-engine');
    $release = makeRelease($package, '1.0.0');
    attachDist($release, $package->name);
    $url = '/v1/instances/me/releases/'.$release->public_id.'/dist';

    $this->getJson($url)->assertUnauthorized();

    $instance = Instance::factory()->create();
    actingAsInstance($instance);
    $instance->forceFill(['status' => Instance::STATUS_REVOKED])->save();
    $this->getJson($url)->assertUnauthorized();
});
