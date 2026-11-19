<?php

use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Release;
use App\Services\Packages\ComposerMetadata;

function composerPackage(bool $paid = false, string $name = 'acme/route-optimizer-api'): ExtensionPackage
{
    return makePackage(ExtensionPackage::KIND_COMPOSER, $name, $paid);
}

it('lists available packages and the metadata url template', function () {
    makeRelease(composerPackage(), '1.0.0');
    makeRelease(composerPackage(name: 'acme/billing-api'), '0.1.0');
    composerPackage(name: 'acme/unreleased-api');
    makeRelease(composerPackage(name: 'acme/review-api'), '1.0.0', status: Release::STATUS_IN_REVIEW);

    $response = $this->get('/composer/packages.json')->assertOk()
        ->assertJsonPath('available-packages', ['acme/billing-api', 'acme/route-optimizer-api'])
        ->assertJsonPath('metadata-url', url('/composer/p2').'/%package%.json');

    expect($response->getContent())->toContain('"packages":{}');
    expect($response->headers->get('ETag'))->not->toBeEmpty();
});

it('lists every published release newest first in the minified v2 format', function () {
    $package = composerPackage();
    makeRelease($package, '1.0.0', publishedAt: '2026-01-01 00:00:00', manifest: [
        'description' => 'Route optimizer API',
        'type' => 'fleetbase-extension',
        'require' => ['php' => '^8.2'],
        'autoload' => ['psr-4' => ['Acme\\RouteOptimizer\\' => 'server/src/']],
        'repositories' => [['type' => 'vcs', 'url' => 'dropped']],
        'config' => ['dropped' => true],
    ]);
    makeRelease($package, '1.1.0', publishedAt: '2026-02-01 00:00:00', manifest: [
        'description' => 'Route optimizer API',
        'type' => 'fleetbase-extension',
        'require' => ['php' => '^8.2', 'fleetbase/core-api' => '^1.6'],
        'autoload' => ['psr-4' => ['Acme\\RouteOptimizer\\' => 'server/src/']],
    ]);
    makeRelease($package, '1.2.0-beta.1', Release::CHANNEL_BETA, manifest: ['description' => 'Beta']);
    makeRelease($package, '0.9.0', status: Release::STATUS_YANKED);

    $response = $this->get('/composer/p2/acme/route-optimizer-api.json')->assertOk()
        ->assertJsonPath('minified', 'composer/2.0');

    $versions = $response->json('packages')['acme/route-optimizer-api'];
    expect(array_column($versions, 'version'))->toBe(['1.2.0-beta.1', '1.1.0', '1.0.0']);

    // Expand the way Composer does and check the full 1.0.0 entry.
    $expanded = composerExpand($versions);
    $v100 = $expanded[2];
    $release = Release::query()->where('version', '1.0.0')->first();
    expect($v100['name'])->toBe('acme/route-optimizer-api')
        ->and($v100['type'])->toBe('fleetbase-extension')
        ->and($v100['require'])->toBe(['php' => '^8.2'])
        ->and($v100['time'])->toBe('2026-01-01T00:00:00+00:00')
        ->and($v100['dist'])->toBe([
            'type' => 'tar',
            'url' => url('/composer/dist/acme/route-optimizer-api/1.0.0.tar'),
            'shasum' => $release->apiTarball->shasum,
            'reference' => $release->apiTarball->checksum,
        ])
        ->and($v100)->not->toHaveKeys(['repositories', 'config']);

    // The beta entry has no `require`; the minifier records that as __unset on the next one.
    expect($expanded[0])->not->toHaveKey('require')
        ->and($expanded[1]['require'])->toBe(['php' => '^8.2', 'fleetbase/core-api' => '^1.6']);
});

it('serves an empty ~dev list and 404s unknown or unreleased packages', function () {
    makeRelease(composerPackage(), '1.0.0');
    composerPackage(name: 'acme/unreleased-api');

    $this->get('/composer/p2/acme/route-optimizer-api~dev.json')->assertOk()
        ->assertExactJson(['packages' => ['acme/route-optimizer-api' => []], 'minified' => 'composer/2.0']);
    $this->get('/composer/p2/acme/unreleased-api.json')->assertNotFound();
    $this->get('/composer/p2/acme/nope.json')->assertNotFound()->assertJsonPath('error', 'Package not found.');
});

it('streams the dist archive with sha1 matching dist.shasum', function () {
    $release = makeRelease(composerPackage(), '1.0.0');

    $response = $this->get('/composer/dist/acme/route-optimizer-api/1.0.0.tar')->assertOk()
        ->assertHeader('ETag', '"'.$release->apiTarball->checksum.'"');

    expect(sha1($response->streamedContent()))->toBe($release->apiTarball->shasum);

    $this->get('/composer/dist/acme/route-optimizer-api/2.0.0.tar')->assertNotFound();
});

it('answers If-None-Match on p2 metadata', function () {
    makeRelease(composerPackage(), '1.0.0');

    $etag = $this->get('/composer/p2/acme/route-optimizer-api.json')->headers->get('ETag');
    $this->withHeader('If-None-Match', $etag)->get('/composer/p2/acme/route-optimizer-api.json')->assertStatus(304);
});

it('skips versions Composer cannot parse', function () {
    $package = composerPackage();
    makeRelease($package, '1.0.0');
    makeRelease($package, '1.1.0-next.1', Release::CHANNEL_BETA);

    $versions = $this->get('/composer/p2/acme/route-optimizer-api.json')->json('packages')['acme/route-optimizer-api'];
    expect(array_column($versions, 'version'))->toBe(['1.0.0']);
});

it('hides suspended listings from packages.json', function () {
    $package = composerPackage();
    makeRelease($package, '1.0.0');
    $package->extension->forceFill(['status' => Extension::STATUS_SUSPENDED])->save();

    $this->get('/composer/packages.json')->assertJsonPath('available-packages', []);
    $this->get('/composer/p2/acme/route-optimizer-api.json')->assertNotFound();
});

it('minifies like Composer', function () {
    $versions = [
        ['name' => 'a/b', 'version' => '2.0.0', 'require' => ['php' => '^8.2'], 'extra' => ['x' => 1]],
        ['name' => 'a/b', 'version' => '1.0.0', 'require' => ['php' => '^8.2']],
    ];

    expect(ComposerMetadata::minify($versions))->toBe([
        $versions[0],
        ['version' => '1.0.0', 'extra' => '__unset'],
    ])->and(composerExpand(ComposerMetadata::minify($versions)))->toBe($versions);
});

/**
 * Composer\MetadataMinifier\MetadataMinifier::expand.
 */
function composerExpand(array $versions): array
{
    $expanded = [];
    $expandedVersion = null;
    foreach ($versions as $versionData) {
        if (! $expandedVersion) {
            $expandedVersion = $versionData;
            $expanded[] = $expandedVersion;

            continue;
        }
        foreach ($versionData as $key => $val) {
            if ($val === '__unset') {
                unset($expandedVersion[$key]);
            } else {
                $expandedVersion[$key] = $val;
            }
        }
        $expanded[] = $expandedVersion;
    }

    return $expanded;
}
