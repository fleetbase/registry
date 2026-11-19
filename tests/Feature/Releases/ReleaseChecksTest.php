<?php

use App\Models\Release;
use App\Models\ReleaseCheck;
use App\Models\Review;

/**
 * Upload and return the release, asserting it failed its checks.
 */
function failingUpload($account, $extension, string $version, array $options = [], array $fields = []): Release
{
    $id = uploadRelease($account, $extension, $version, $options, $fields)->assertCreated()
        ->assertJsonPath('status', Release::STATUS_FAILED_CHECKS)
        ->assertJsonPath('checks_passed', false)
        ->json('id');

    return Release::findById($id);
}

it('validates extension.json against the schema and the uploaded archives', function () {
    [$account, , $extension] = publisher();

    $release = failingUpload($account, $extension, '1.0.0', ['manifest' => ['name' => '', 'keywords' => 'not-a-list', 'compat' => ['console' => '>=0.7', 'cloud' => 'x']]]);
    $output = checkOf($release, ReleaseCheck::CHECK_MANIFEST);
    expect($output['passed'])->toBeFalse()
        ->and(implode("\n", $output['errors']))->toContain('/name')->toContain('/keywords')->toContain('/compat');

    $release = failingUpload($account, $extension, '1.0.1', ['api' => false, 'manifest' => ['api' => 'acme/thing']]);
    expect(checkOf($release, ReleaseCheck::CHECK_MANIFEST)['errors'])->toContain('The manifest declares `api` but no api tarball was uploaded.');

    $release = failingUpload($account, $extension, '1.0.2', ['manifest' => ['engine' => null]]);
    expect(checkOf($release, ReleaseCheck::CHECK_MANIFEST)['errors'])->toContain('an engine tarball was uploaded but the manifest has no `engine` entry.');

    // Only the failing check fails.
    expect($release->checks()->where('passed', false)->pluck('check')->all())->toBe([ReleaseCheck::CHECK_MANIFEST]);
});

it('requires compat ranges that parse and warns when they are missing', function () {
    [$account, , $extension] = publisher();

    $release = failingUpload($account, $extension, '1.0.0', ['manifest' => ['compat' => ['console' => '>=0.7 <<1', 'api' => '^1.6']]]);
    expect(checkOf($release, ReleaseCheck::CHECK_COMPAT)['errors'])->toBe(['compat.console `>=0.7 <<1` is not a valid version range.']);

    $id = uploadRelease($account, $extension, '1.0.1', ['manifest' => ['compat' => null]])->assertCreated()->json('id');
    $compat = checkOf($id, ReleaseCheck::CHECK_COMPAT);
    expect($compat['passed'])->toBeTrue()
        ->and($compat['warnings'])->toContain('No compat.console range: the release is offered to every console version.');
});

it('requires the tarball package names to be the listing\'s', function () {
    [$account, , $extension] = publisher();
    $npm = packageName($extension, 'npm');

    $release = failingUpload($account, $extension, '1.0.0', ['engineJson' => ['name' => '@someone/else']]);
    expect(checkOf($release, ReleaseCheck::CHECK_PACKAGE_NAMES)['errors'])->toContain("The engine tarball is @someone/else, but the listing's npm package is {$npm}.");

    $release = failingUpload($account, $extension, '1.0.1', ['composerJson' => ['name' => 'someone/else']]);
    expect(implode(' ', checkOf($release, ReleaseCheck::CHECK_PACKAGE_NAMES)['errors']))->toContain('The api tarball is someone/else');

    $release = failingUpload($account, $extension, '1.0.2', ['engineJson' => ['version' => '9.9.9']]);
    expect(checkOf($release, ReleaseCheck::CHECK_PACKAGE_NAMES)['errors'])->toContain('package.json version 9.9.9 does not match the release version 1.0.2.');

    $release = failingUpload($account, $extension, '1.0.3', ['engine' => ['package/package.json' => 'not json']]);
    expect(checkOf($release, ReleaseCheck::CHECK_PACKAGE_NAMES)['errors'])->toContain('The engine tarball has no readable package.json at its root or in its top-level folder.');

    $extension->packages()->where('kind', 'composer')->delete();
    $release = failingUpload($account, $extension, '1.0.4');
    expect(implode(' ', checkOf($release, ReleaseCheck::CHECK_PACKAGE_NAMES)['errors']))->toContain('The listing has no composer package');
});

it('requires a version greater than the last release on the channel', function () {
    [$account, , $extension] = publisher(extension: ['status' => 'published']);
    publishedRelease($account, $extension, '1.2.0');

    $release = failingUpload($account, $extension, '1.1.9');
    expect(checkOf($release, ReleaseCheck::CHECK_VERSION)['errors'])->toBe(['1.1.9 is not greater than 1.2.0, the last stable release.']);

    // The beta channel is compared on its own.
    $beta = uploadRelease($account, $extension, '1.1.0-beta.1', [], ['channel' => 'beta'])->assertCreated()->json('id');
    expect(checkOf($beta, ReleaseCheck::CHECK_VERSION)['passed'])->toBeTrue();

    // Composer must be able to install the version when there is an api package.
    $release = failingUpload($account, $extension, '1.3.0-next.1', [], ['channel' => 'beta']);
    expect(implode(' ', checkOf($release, ReleaseCheck::CHECK_VERSION)['errors']))->toContain('Composer cannot install 1.3.0-next.1');
    $engineOnly = uploadRelease($account, $extension, '1.3.0-next.2', ['api' => false], ['channel' => 'beta'])->assertCreated()->json('id');
    expect(checkOf($engineOnly, ReleaseCheck::CHECK_VERSION)['passed'])->toBeTrue();

    $stablePrerelease = uploadRelease($account, $extension, '1.4.0-rc.1')->assertCreated()->json('id');
    expect(checkOf($stablePrerelease, ReleaseCheck::CHECK_VERSION)['warnings'][0])->toContain('is a prerelease on the stable channel');
});

it('enforces size limits', function () {
    [$account, , $extension] = publisher();

    config(['registry.releases.max_file_bytes' => 1000]);
    $release = failingUpload($account, $extension, '1.0.0', ['engine' => ['package/big.js' => str_repeat('a', 2000)]]);
    expect(checkOf($release, ReleaseCheck::CHECK_SIZE)['errors'])->toContain('engine:package/big.js is 2 KB; files are limited to 1000 B.');

    config(['registry.releases.max_file_bytes' => 25 * 1024 * 1024, 'registry.releases.max_entries' => 2]);
    $release = failingUpload($account, $extension, '1.0.1', ['engine' => ['package/a.js' => 'a', 'package/b.js' => 'b']]);
    expect(checkOf($release, ReleaseCheck::CHECK_SIZE)['errors'])->toContain('The engine archive has more than 2 entries.');

    config(['registry.releases.max_entries' => 20000, 'registry.releases.max_unpacked_bytes' => 100]);
    $release = failingUpload($account, $extension, '1.0.2');
    expect(implode(' ', checkOf($release, ReleaseCheck::CHECK_SIZE)['errors']))->toContain('unpacks to more than 100 B');

    config(['registry.releases.max_unpacked_bytes' => 300 * 1024 * 1024, 'registry.max_bundle_bytes' => 100]);
    $release = failingUpload($account, $extension, '1.0.3');
    expect(implode(' ', checkOf($release, ReleaseCheck::CHECK_SIZE)['errors']))->toContain('the limit is 100 B');
});

it('rejects path traversal, links, devices, forbidden file types and native binaries', function (array $entry, string $message) {
    [$account, , $extension] = publisher();

    $release = failingUpload($account, $extension, '1.0.0', ['engine' => $entry]);
    expect(implode("\n", checkOf($release, ReleaseCheck::CHECK_ARCHIVE)['errors']))->toContain($message);
})->with([
    'parent segment' => [['package/../../evil.js' => 'x'], 'engine:package/../../evil.js escapes the package root'],
    'absolute path' => [['/etc/cron.d/evil' => 'x'], 'engine:/etc/cron.d/evil escapes the package root'],
    'windows path' => [['..\\evil.js' => 'x'], 'escapes the package root'],
    'symlink' => [['package/link' => ['type' => '2', 'link' => '/etc/passwd']], 'engine:package/link is a symbolic link (to /etc/passwd)'],
    'hard link' => [['package/hard' => ['type' => '1', 'link' => 'package/index.js']], 'is a hard link'],
    'device' => [['package/dev' => ['type' => '3']], 'is a device file'],
    'fifo' => [['package/fifo' => ['type' => '6']], 'is a FIFO'],
    'exe' => [['package/setup.exe' => 'MZ'], '.exe files are not allowed'],
    'elf binary' => [['package/helper.js' => "\x7fELF\x02\x01\x01".str_repeat("\0", 64)], 'engine:package/helper.js is a native binary (ELF executable)'],
]);

it('requires readable tar archives and a gzipped engine', function () {
    [$account, , $extension] = publisher();

    $release = failingUpload($account, $extension, '1.0.0', ['gzipEngine' => false]);
    expect(checkOf($release, ReleaseCheck::CHECK_ARCHIVE)['errors'])->toBe(['The engine tarball must be gzip-compressed (npm pack output).']);

    $files = releaseFiles($extension, '1.0.1');
    $files['api'] = fakeUpload(str_repeat('not a tar archive ', 100), 'api.tar');
    $id = uploadRelease($account, $extension, '1.0.1', [], ['api' => $files['api']])->assertCreated()->assertJsonPath('status', Release::STATUS_FAILED_CHECKS)->json('id');
    expect(checkOf($id, ReleaseCheck::CHECK_ARCHIVE)['errors'][0])->toContain('The api upload is not a readable tar or tar.gz archive');
});

it('scans for secrets with the built-in ruleset and redacts what it finds', function () {
    [$account, , $extension] = publisher();
    $key = 'AKIA'.'QYLPMN5HHHFPZAM2';

    $release = failingUpload($account, $extension, '1.0.0', [
        'engine' => ['package/config.js' => "module.exports = {\n  key: '{$key}',\n};"],
        'api' => ['.env' => 'APP_KEY=base64:abc', 'src/Allowed.php' => '<?php // ghp_'.str_repeat('a', 36).' gitleaks:allow'],
    ]);

    $output = checkOf($release, ReleaseCheck::CHECK_SECRETS_SCAN);
    expect($output['passed'])->toBeFalse()
        ->and($output['summary'])->toBe('2 possible secrets found.')
        ->and($output['errors'][0])->toBe('engine:package/config.js:2 — AWS access key id (AKIAQ…[20 chars])')
        ->and($output['errors'][1])->toBe('api:.env — Environment file (.env)')
        ->and($output['scanners'])->toBe(['builtin'])
        ->and($output['warnings'])->toContain('gitleaks is not installed; only the built-in ruleset was applied.')
        ->and(json_encode($release->checks()->get()))->not->toContain($key);

    // The failure notice names the failing checks.
    expect($release->reviews()->get()->last()->reason)->toBe('Failed checks: secrets_scan.');
});

it('can turn the secrets scanner off, with a warning', function () {
    [$account, , $extension] = publisher();
    config(['registry.releases.secrets_scanner' => 'off']);

    $id = uploadRelease($account, $extension, '1.0.0', ['engine' => ['package/k.js' => 'AKIA'.'QYLPMN5HHHFPZAM2']])->assertCreated()->json('id');
    $output = checkOf($id, ReleaseCheck::CHECK_SECRETS_SCAN);
    expect($output['passed'])->toBeTrue()->and($output['skipped'])->toBeTrue()
        ->and($output['warnings'][0])->toContain('REGISTRY_SECRETS_SCANNER=off');
});

it('runs gitleaks when the binary is installed', function () {
    [$account, , $extension] = publisher();

    // A stand-in for gitleaks that reports one finding in every scanned directory and
    // records the files it was given.
    $dir = sys_get_temp_dir().'/fake-gitleaks-'.uniqid();
    mkdir($dir);
    $seen = $dir.'/seen.txt';
    $script = $dir.'/gitleaks';
    file_put_contents($script, <<<SH
#!/bin/sh
while [ \$# -gt 0 ]; do
  case "\$1" in
    --source) SRC="\$2"; shift ;;
    --report-path) OUT="\$2"; shift ;;
  esac
  shift
done
(cd "\$SRC" && find . -type f | sort) > {$seen}
printf '[{"RuleID":"generic-api-key","Description":"Generic API Key","File":"%s/engine/package/index.js","StartLine":1,"Secret":"REDACTED"}]' "\$SRC" > "\$OUT"
SH);
    chmod($script, 0755);
    config(['registry.releases.gitleaks_binary' => $script]);

    $release = failingUpload($account, $extension, '1.0.0');
    $output = checkOf($release, ReleaseCheck::CHECK_SECRETS_SCAN);

    expect($output['scanners'])->toBe(['builtin', 'gitleaks'])
        ->and($output['errors'][0])->toBe('engine:package/index.js:1 — Generic API Key (RE…[8 chars])')
        ->and(file_get_contents($seen))->toContain('./engine/package/index.js')->toContain('./api/composer.json');

    // The extracted tree is removed afterwards.
    expect(glob(sys_get_temp_dir().'/release-*'))->toBe([]);
});

it('checks the prebuilt dist manifest', function () {
    [$account, , $extension] = publisher();
    $npm = packageName($extension, 'npm');

    $id = uploadRelease($account, $extension, '1.0.0', ['dist' => true])->assertCreated()
        ->assertJsonPath('status', Release::STATUS_UPLOADED)
        ->assertJsonPath('dist.built_against.@fleetbase/ember-ui', '0.4.6')
        ->json('id');
    $dist = checkOf($id, ReleaseCheck::CHECK_DIST);
    expect($dist['passed'])->toBeTrue()
        ->and($dist['summary'])->toBe('Prebuilt dist built against @fleetbase/ember-ui 0.4.6, @fleetbase/ember-core 0.3.2.')
        ->and($dist['manifest'])->toBe($npm.'/manifest.json');
    expect(Release::findById($id)->engineDist)->not->toBeNull();

    $release = failingUpload($account, $extension, '1.0.1', ['dist' => ['manifest' => ['builtAgainst' => ['@fleetbase/ember-core' => null]]]]);
    expect(checkOf($release, ReleaseCheck::CHECK_DIST)['errors'])->toBe(['manifest.json builtAgainst has no @fleetbase/ember-core version.']);

    $release = failingUpload($account, $extension, '1.0.2', ['dist' => ['manifest' => ['name' => '@other/engine', 'version' => '0.0.1']]]);
    expect(checkOf($release, ReleaseCheck::CHECK_DIST)['errors'])->toBe([
        "manifest.json names @other/engine, not the engine package {$npm}.",
        'manifest.json version 0.0.1 does not match the release version 1.0.2.',
    ]);

    $release = failingUpload($account, $extension, '1.0.3', ['dist' => ['tamper' => ['routes.js' => 'tampered', 'translations.json' => '[]', 'engines-dist/assets/engine.js' => 'x', 'host-app.js' => 'y']]]);
    expect(checkOf($release, ReleaseCheck::CHECK_DIST)['errors'][0])->toBe("dist:{$npm}/routes.js does not match its sha256 in manifest.json.");

    $release = failingUpload($account, $extension, '1.0.4', ['dist' => true, 'engine' => false]);
    expect(checkOf($release, ReleaseCheck::CHECK_DIST)['errors'])->toContain('A prebuilt dist needs the engine tarball it was built from.');
});

it('records every check transition in the review timeline', function () {
    [$account, , $extension] = publisher();

    $release = failingUpload($account, $extension, '1.0.0', ['engineJson' => ['name' => '@x/y']]);

    expect($release->reviews()->get()->map(fn (Review $r) => $r->action.':'.$r->to_status)->all())
        ->toBe(['upload:uploaded', 'check:checking', 'fail_checks:failed_checks']);
});
