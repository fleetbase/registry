<?php

namespace App\Releases\Checks;

use App\Models\ExtensionPackage;
use App\Models\ReleaseCheck;
use App\Support\Semver;

/**
 * The optional `dist` archive: a prebuilt engine release written by ember-core's
 * `fleetbase-build-engine-release` (the `<name>/` folder with `manifest.json`). Its
 * manifest must name the engine package and the release version, record the
 * `@fleetbase/ember-ui` and `@fleetbase/ember-core` versions it was built against (the
 * installer refuses a host with a different minor), carry the `runtime` entry, and list
 * file hashes that match the archive.
 */
class DistCheck implements Check
{
    public const PINNED_PACKAGES = ['@fleetbase/ember-ui', '@fleetbase/ember-core'];

    public const LISTED = 25;

    public function name(): string
    {
        return ReleaseCheck::CHECK_DIST;
    }

    public function run(CheckContext $context): CheckResult
    {
        $inventory = $context->inventory('dist');
        if ($context->file('dist') === null || $inventory === null) {
            return CheckResult::skip('No prebuilt dist uploaded.');
        }

        if ($inventory->error !== null) {
            return CheckResult::fail('The dist archive is unreadable.', ['The dist upload is not a readable tar or tar.gz archive.']);
        }

        $errors = [];
        $warnings = [];

        if ($context->file('engine') === null) {
            $errors[] = 'A prebuilt dist needs the engine tarball it was built from.';
        }

        // The shallowest manifest.json is the release manifest (`<name>/manifest.json`).
        $candidates = array_filter(array_keys($inventory->kept), fn (string $name) => basename($name) === 'manifest.json');
        usort($candidates, fn ($a, $b) => substr_count($a, '/') <=> substr_count($b, '/') ?: strcmp($a, $b));
        $path = $candidates[0] ?? null;

        if ($path === null) {
            return CheckResult::fail('The dist has no manifest.json.', array_merge($errors, ['The dist archive has no manifest.json; build it with `fleetbase-build-engine-release`.']));
        }

        $manifest = json_decode($inventory->kept[$path], true);
        if (! is_array($manifest)) {
            return CheckResult::fail('The dist manifest.json is invalid.', array_merge($errors, ["{$path} is not valid JSON."]));
        }

        $builtAgainst = [];
        foreach (self::PINNED_PACKAGES as $package) {
            $version = $manifest['builtAgainst'][$package] ?? null;
            if (! is_string($version) || Semver::normalize($version) === null) {
                $errors[] = "manifest.json builtAgainst has no {$package} version.";
            } else {
                $builtAgainst[$package] = $version;
            }
        }

        $enginePackage = strtolower((string) ($context->file('engine')?->manifest()['name'] ?? ($context->listingPackages(ExtensionPackage::KIND_NPM)[0] ?? '')));
        if (($manifest['name'] ?? null) !== $enginePackage && $enginePackage !== '') {
            $errors[] = 'manifest.json names '.($manifest['name'] ?? '(nothing)').", not the engine package {$enginePackage}.";
        }

        if (($manifest['version'] ?? null) !== $context->release->version) {
            $errors[] = 'manifest.json version '.($manifest['version'] ?? '(missing)').' does not match the release version '.$context->release->version.'.';
        }

        if (! is_array($manifest['runtime'] ?? null)) {
            $errors[] = 'manifest.json has no runtime entry.';
        }

        if (($manifest['schema'] ?? null) !== 1) {
            $warnings[] = 'manifest.json schema '.json_encode($manifest['schema'] ?? null).' is not the release schema this registry knows (1).';
        }

        $errors = array_merge($errors, $this->verifyFiles($inventory, $path, (array) ($manifest['files'] ?? []), $warnings));

        $context->meta['dist'] = [
            'manifest' => $path,
            'name' => $manifest['name'] ?? null,
            'version' => $manifest['version'] ?? null,
            'schema' => $manifest['schema'] ?? null,
            'built_against' => $manifest['builtAgainst'] ?? [],
        ];

        return CheckResult::from(
            $errors,
            'Prebuilt dist built against '.collect($builtAgainst)->map(fn ($v, $k) => "{$k} {$v}")->implode(', ').'.',
            'The prebuilt dist is invalid.',
            $warnings,
            ['manifest' => $path, 'built_against' => $manifest['builtAgainst'] ?? []],
        );
    }

    /**
     * `files` maps paths relative to the manifest's folder to `sha256-<hex>`.
     */
    protected function verifyFiles($inventory, string $manifestPath, array $files, array &$warnings): array
    {
        if ($files === []) {
            $warnings[] = 'manifest.json lists no file hashes.';

            return [];
        }

        $base = dirname($manifestPath) === '.' ? '' : dirname($manifestPath).'/';
        $problems = [];

        foreach ($files as $relative => $digest) {
            $entry = $inventory->file($base.$relative);
            if ($entry === null) {
                $problems[] = "dist:{$base}{$relative} is listed in manifest.json but missing.";
            } elseif ($entry['sha256'] === null) {
                $warnings[] = "dist:{$base}{$relative} is too large to verify.";
            } elseif (! is_string($digest) || ! hash_equals(strtolower(preg_replace('/^sha256-/', '', $digest)), $entry['sha256'])) {
                $problems[] = "dist:{$base}{$relative} does not match its sha256 in manifest.json.";
            }
        }

        if (count($problems) > self::LISTED) {
            $problems = array_merge(array_slice($problems, 0, self::LISTED), ['and '.(count($problems) - self::LISTED).' more file mismatches.']);
        }

        return $problems;
    }
}
