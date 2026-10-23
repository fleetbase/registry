<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Release;

function npmPackage(bool $paid = false, string $name = '@acme/route-optimizer-engine', array $extension = []): ExtensionPackage
{
    return makePackage(ExtensionPackage::KIND_NPM, $name, $paid, $extension);
}

it('serves a packument for a scoped name sent URL-encoded', function () {
    $package = npmPackage(extension: ['subtitle' => 'Plan better routes']);
    makeRelease($package, '1.0.0', publishedAt: '2026-01-01 10:00:00', manifest: [
        'description' => 'Engine',
        'dependencies' => ['ember-concurrency' => '^3.0.0'],
        'peerDependencies' => [],
        '_private' => 'dropped',
        'readme' => 'dropped',
    ]);

    $response = $this->get('/npm/@acme%2froute-optimizer-engine')->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertJsonPath('_id', '@acme/route-optimizer-engine')
        ->assertJsonPath('name', '@acme/route-optimizer-engine')
        ->assertJsonPath('description', 'Plan better routes')
        ->assertJsonPath('dist-tags.latest', '1.0.0')
        ->assertJsonPath('time.created', '2026-01-01T10:00:00.000Z');

    $version = $response->json('versions')['1.0.0'];
    $file = Release::query()->where('version', '1.0.0')->first()->engineTarball;
    expect($version['name'])->toBe('@acme/route-optimizer-engine')
        ->and($version['version'])->toBe('1.0.0')
        ->and($version['_id'])->toBe('@acme/route-optimizer-engine@1.0.0')
        ->and($version['description'])->toBe('Engine')
        ->and($version['dependencies'])->toBe(['ember-concurrency' => '^3.0.0'])
        ->and($version['dist']['tarball'])->toBe(url('/npm/@acme/route-optimizer-engine/-/route-optimizer-engine-1.0.0.tgz'))
        ->and($response->json('time')['1.0.0'])->toBe('2026-01-01T10:00:00.000Z')
        ->and($version['dist']['shasum'])->toBe($file->shasum)
        ->and($version['dist']['integrity'])->toBe($file->integrity)
        ->and($version)->not->toHaveKeys(['_private', 'readme']);

    // Empty objects stay objects for npm.
    expect($response->getContent())->toContain('"peerDependencies":{}');

    // Unencoded and upper-case encoded forms resolve the same package.
    $this->get('/npm/@acme/route-optimizer-engine')->assertOk();
    $this->get('/npm/@acme%2Froute-optimizer-engine')->assertOk();
});

it('builds versions from published, non-yanked releases and orders dist-tags by semver', function () {
    $package = npmPackage();
    makeRelease($package, '1.2.0');
    makeRelease($package, '1.10.0', publishedAt: now()->subYear()->toDateTimeString());
    makeRelease($package, '2.0.0', status: Release::STATUS_YANKED);
    makeRelease($package, '3.0.0', status: Release::STATUS_IN_REVIEW);
    makeRelease($package, '2.0.0-beta.2', Release::CHANNEL_BETA);
    makeRelease($package, '2.0.0-beta.10', Release::CHANNEL_BETA);

    $response = $this->get('/npm/@acme%2froute-optimizer-engine')->assertOk();

    expect(array_keys($response->json('versions')))->toBe(['1.2.0', '1.10.0', '2.0.0-beta.2', '2.0.0-beta.10'])
        ->and($response->json('dist-tags'))->toBe(['latest' => '1.10.0', 'beta' => '2.0.0-beta.10']);
});

it('falls back to the highest beta for latest when nothing stable is published', function () {
    $package = npmPackage();
    makeRelease($package, '0.1.0-beta.1', Release::CHANNEL_BETA);

    $this->get('/npm/@acme%2froute-optimizer-engine')->assertOk()
        ->assertJsonPath('dist-tags', ['latest' => '0.1.0-beta.1', 'beta' => '0.1.0-beta.1']);
});

it('answers If-None-Match with 304 and changes the ETag when a release is published', function () {
    $package = npmPackage();
    makeRelease($package, '1.0.0');

    $first = $this->get('/npm/@acme%2froute-optimizer-engine')->assertOk();
    $etag = $first->headers->get('ETag');
    expect($etag)->not->toBeEmpty()
        ->and($first->headers->get('Cache-Control'))->toContain('public');

    $this->withHeader('If-None-Match', $etag)->get('/npm/@acme%2froute-optimizer-engine')
        ->assertStatus(304)->assertContent('');

    makeRelease($package, '1.1.0');
    $this->withHeader('If-None-Match', $etag)->get('/npm/@acme%2froute-optimizer-engine')
        ->assertOk()->assertJsonPath('dist-tags.latest', '1.1.0');
});

it('streams the tarball with its checksum ETag', function () {
    $package = npmPackage();
    $release = makeRelease($package, '1.0.0');
    $file = $release->engineTarball;

    $response = $this->get('/npm/@acme/route-optimizer-engine/-/route-optimizer-engine-1.0.0.tgz')->assertOk()
        ->assertHeader('Content-Type', 'application/octet-stream')
        ->assertHeader('Content-Length', (string) $file->size)
        ->assertHeader('ETag', '"'.$file->checksum.'"');

    expect(sha1($response->streamedContent()))->toBe($file->shasum)
        ->and($response->headers->get('Cache-Control'))->toContain('immutable');

    $this->withHeader('If-None-Match', '"'.$file->checksum.'"')
        ->get('/npm/@acme/route-optimizer-engine/-/route-optimizer-engine-1.0.0.tgz')
        ->assertStatus(304);
});

it('keeps yanked tarballs downloadable for existing lockfiles', function () {
    $package = npmPackage();
    makeRelease($package, '1.0.0', status: Release::STATUS_YANKED);

    $this->get('/npm/@acme/route-optimizer-engine/-/route-optimizer-engine-1.0.0.tgz')->assertOk();
    $this->get('/npm/@acme%2froute-optimizer-engine')->assertNotFound();
});

it('serves the prebuilt dist of a release, verified by the sha256 catalog releases carry', function () {
    $package = npmPackage();
    $release = makeRelease($package, '1.0.0');
    $file = attachDist($release, $package->name);

    $response = $this->get('/npm/@acme/route-optimizer-engine/-/dist/1.0.0')->assertOk()
        ->assertHeader('Content-Type', 'application/gzip')
        ->assertHeader('X-Checksum-Sha256', $file->checksum)
        ->assertHeader('ETag', '"'.$file->checksum.'"');

    expect(hash('sha256', $response->streamedContent()))->toBe($file->checksum)
        ->and($response->headers->get('Cache-Control'))->toContain('immutable');

    $this->withHeader('If-None-Match', '"'.$file->checksum.'"')->get('/npm/@acme/route-optimizer-engine/-/dist/1.0.0')->assertStatus(304);
    $this->get('/npm/@acme%2froute-optimizer-engine/-/dist/1.0.0')->assertOk();
});

it('keeps the dist of a yanked release downloadable and 404s releases without one', function () {
    $package = npmPackage();
    attachDist(makeRelease($package, '1.0.0', status: Release::STATUS_YANKED), $package->name);
    makeRelease($package, '1.1.0');
    attachDist(makeRelease($package, '2.0.0', status: Release::STATUS_IN_REVIEW), $package->name);

    $this->get('/npm/@acme/route-optimizer-engine/-/dist/1.0.0')->assertOk();
    $this->get('/npm/@acme/route-optimizer-engine/-/dist/1.1.0')->assertNotFound()->assertJsonPath('error', 'Dist not found.');
    $this->get('/npm/@acme/route-optimizer-engine/-/dist/2.0.0')->assertNotFound();
    $this->get('/npm/@acme/route-optimizer-engine/-/dist/9.9.9')->assertNotFound();
    $this->get('/npm/@acme/missing-engine/-/dist/1.0.0')->assertNotFound()->assertJsonPath('error', 'Package not found.');
});

it('returns npm-readable 404s', function () {
    $package = npmPackage();
    makeRelease($package, '1.0.0', status: Release::STATUS_IN_REVIEW);

    $this->get('/npm/@acme%2fmissing')->assertNotFound()->assertJsonPath('error', 'Package not found.');
    $this->get('/npm/@acme%2froute-optimizer-engine')->assertNotFound();
    $this->get('/npm/@acme/route-optimizer-engine/-/route-optimizer-engine-1.0.0.tgz')->assertNotFound();
    $this->get('/npm/@acme/route-optimizer-engine/-/other-1.0.0.tgz')->assertNotFound()->assertJsonPath('error', 'Tarball not found.');
    $this->get('/npm/@acme/route-optimizer-engine/-/route-optimizer-engine-9.9.9.tgz')->assertNotFound();
});

it('hides packages of suspended listings', function () {
    $package = npmPackage();
    makeRelease($package, '1.0.0');
    $package->extension->forceFill(['status' => Extension::STATUS_SUSPENDED])->save();

    $this->get('/npm/@acme%2froute-optimizer-engine')->assertNotFound();
    $this->get('/npm/@acme/route-optimizer-engine/-/route-optimizer-engine-1.0.0.tgz')->assertNotFound();
});

it('refuses npm publish and other writes with a pointer to flb publish', function (string $method, string $uri) {
    $this->json($method, $uri, ['name' => '@acme/x'])
        ->assertStatus(405)
        ->assertHeader('Allow', 'GET, HEAD')
        ->assertJsonPath('error', fn (string $message) => str_contains($message, 'flb publish'));
})->with([
    ['PUT', '/npm/@acme%2froute-optimizer-engine'],
    ['PUT', '/npm/unscoped-engine'],
    ['DELETE', '/npm/@acme%2froute-optimizer-engine/-rev/1-abc'],
    ['PUT', '/npm/-/package/@acme%2fx/dist-tags/latest'],
]);

it('answers npm ping and whoami', function () {
    $this->get('/npm/-/ping')->assertOk()->assertExactJson([]);
    $this->get('/npm/-/whoami')->assertUnauthorized();

    $account = Account::factory()->create(['username' => 'ron']);
    actingAsAccount($account, ['install']);
    $this->get('/npm/-/whoami')->assertOk()->assertJsonPath('username', 'ron');
});

it('serves unscoped package names', function () {
    $package = npmPackage(name: 'legacy-engine');
    makeRelease($package, '0.4.0');

    $versions = $this->get('/npm/legacy-engine')->assertOk()->json('versions');
    expect($versions['0.4.0']['dist']['tarball'])->toBe(url('/npm/legacy-engine/-/legacy-engine-0.4.0.tgz'));
    $this->get('/npm/legacy-engine/-/legacy-engine-0.4.0.tgz')->assertOk();
});
