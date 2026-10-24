<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Release;
use App\Models\Token;
use App\Releases\ReleasePipeline;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;

/**
 * An account with a role in a fresh organization and a draft listing that is ready for its
 * first release, owning `@<org slug>/<slug>-engine` and `<org slug>/<slug>-api`.
 *
 * @return array{0: Account, 1: Organization, 2: Extension}
 */
function publisher(string $role = Membership::ROLE_OWNER, array $extension = []): array
{
    $account = Account::factory()->create();
    $organization = Organization::factory()->create();
    $account->organizations()->attach($organization->uuid, ['role' => $role]);

    $listing = Extension::factory()->listingReady()->free()->for($organization)->state($extension)->create();
    $listing->packages()->createMany([
        ['kind' => ExtensionPackage::KIND_NPM, 'name' => '@'.$organization->slug.'/'.$listing->slug.'-engine'],
        ['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => $organization->slug.'/'.$listing->slug.'-api'],
    ]);

    return [$account, $organization, $listing->load('packages')];
}

function staffAccount(array $scopes = [Token::SCOPE_REVIEW]): Account
{
    $account = Account::factory()->create(['name' => 'Staff Reviewer']);
    $organization = Organization::factory()->staff()->create();
    $account->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_MEMBER]);

    return $account;
}

function packageName(Extension $extension, string $kind): ?string
{
    return $extension->packages()->where('kind', $kind)->value('name');
}

/**
 * The multipart fields of a release upload. Options:
 *   manifest   array merged into extension.json (null values remove keys)
 *   engine     false to leave out, or extra `path => contents` for the tarball
 *   api        same for the api archive
 *   engineJson / composerJson  merged into package.json / composer.json
 *   dist       true for a valid prebuilt dist, or ['manifest' => [...], 'files' => [...]] overrides
 *   gzipEngine false to upload a plain tar as the engine
 */
function releaseFiles(Extension $extension, string $version, array $options = []): array
{
    $npm = packageName($extension, ExtensionPackage::KIND_NPM) ?? '@acme/'.$extension->slug.'-engine';
    $composer = packageName($extension, ExtensionPackage::KIND_COMPOSER) ?? 'acme/'.$extension->slug.'-api';

    $manifest = array_merge([
        'name' => $extension->name,
        'version' => $version,
        'engine' => $npm,
        'api' => $composer,
        'compat' => ['console' => '>=0.7.0 <0.8.0', 'api' => '^1.6'],
    ], $options['manifest'] ?? []);
    $manifest = array_filter($manifest, fn ($value) => $value !== null);
    foreach (['engine', 'api'] as $part) {
        if (($options[$part] ?? null) === false && ! array_key_exists($part, $options['manifest'] ?? [])) {
            unset($manifest[$part]);
        }
    }

    $fields = ['manifest' => fakeUpload(json_encode($manifest), 'extension.json')];

    if (($options['engine'] ?? null) !== false) {
        $packageJson = array_merge(['name' => $npm, 'version' => $version, 'main' => 'index.js'], $options['engineJson'] ?? []);
        $files = array_merge([
            'package/package.json' => json_encode($packageJson),
            'package/index.js' => 'module.exports = {};',
        ], is_array($options['engine'] ?? null) ? $options['engine'] : []);
        $fields['engine'] = new UploadedFile(makeTarball($files, $options['gzipEngine'] ?? true), 'engine.tgz', 'application/gzip', null, true);
    }

    if (($options['api'] ?? null) !== false) {
        $composerJson = array_merge(['name' => $composer, 'type' => 'library', 'autoload' => ['psr-4' => ['Acme\\' => 'src/']]], $options['composerJson'] ?? []);
        $files = array_merge([
            'composer.json' => json_encode($composerJson),
            'src/Extension.php' => '<?php namespace Acme; class Extension {}',
        ], is_array($options['api'] ?? null) ? $options['api'] : []);
        $fields['api'] = new UploadedFile(makeTarball($files), 'api.tar.gz', 'application/gzip', null, true);
    }

    if (! empty($options['dist'])) {
        $fields['dist'] = new UploadedFile(makeDist($npm, $version, is_array($options['dist']) ? $options['dist'] : []), 'dist.tgz', 'application/gzip', null, true);
    }

    return $fields;
}

/**
 * A prebuilt engine release laid out as ember-core's build-engine-release writes it.
 */
function makeDist(string $name, string $version, array $overrides = []): string
{
    $files = array_merge([
        'routes.js' => 'define("x/routes", [], function () {});',
        'host-app.js' => 'define("@fleetbase/console/x", [], function () {});',
        'translations.json' => '[["en-us", {}]]',
        'engines-dist/assets/engine.js' => 'define("x/engine", [], function () {});',
    ], $overrides['files'] ?? []);

    $manifest = array_replace_recursive([
        'schema' => 1,
        'name' => $name,
        'version' => $version,
        'builtWith' => ['console' => '0.7.69', 'environment' => 'production'],
        'builtAgainst' => ['@fleetbase/ember-ui' => '0.4.6', '@fleetbase/ember-core' => '0.3.2', 'ember-source' => '5.4.0'],
        'runtime' => ['mount' => ['as' => 'x', 'path' => 'x', 'root' => false], 'routes' => 'routes.js', 'hostApp' => 'host-app.js'],
        'files' => collect($files)->map(fn ($contents) => 'sha256-'.hash('sha256', $contents))->all(),
    ], $overrides['manifest'] ?? []);

    $entries = [$name.'/manifest.json' => json_encode($manifest)];
    foreach ($overrides['tamper'] ?? $files as $path => $contents) {
        $entries[$name.'/'.$path] = $contents;
    }

    return makeTarball($entries);
}

function fakeUpload(string $contents, string $name): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'application/octet-stream', null, true);
}

/**
 * Upload a release as the given account (publish scope) and return the response.
 */
function uploadRelease(Account $account, Extension $extension, string $version, array $options = [], array $fields = []): TestResponse
{
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    return test()->post('/v1/publisher/extensions/'.$extension->slug.'/releases', array_merge(releaseFiles($extension, $version, $options), $fields), ['Accept' => 'application/json']);
}

/**
 * The output of one check of a release.
 */
function checkOf(Release|string $release, string $check): ?array
{
    $release = $release instanceof Release ? $release : Release::findById($release);
    $row = $release->checks()->where('check', $check)->first();

    return $row ? ['passed' => $row->passed] + ($row->output ?? []) : null;
}

/**
 * A release taken through upload, checks, submit and staff approval: published.
 */
function publishedRelease(Account $publisher, Extension $extension, string $version, array $options = []): Release
{
    $id = uploadRelease($publisher, $extension, $version, $options, ['submit' => '1'])->assertCreated()->json('id');
    $release = Release::findById($id);

    if ($release->status === Release::STATUS_SUBMITTED) {
        app(ReleasePipeline::class)->approve($release, staffAccount());
    }

    return $release->fresh();
}
