<?php

namespace App\Releases\Checks;

use App\Models\ExtensionPackage;
use App\Models\ReleaseCheck;
use App\Releases\ExtensionManifest;

/**
 * The names inside the tarballs (`package.json`, `composer.json`) must be package names the
 * listing owns (`extension_packages`), and agree with the manifest's `engine` / `api`
 * entries. The engine's `package.json` version must equal the release version.
 */
class PackageNamesCheck implements Check
{
    public function name(): string
    {
        return ReleaseCheck::CHECK_PACKAGE_NAMES;
    }

    public function run(CheckContext $context): CheckResult
    {
        $errors = [];
        $names = [];

        foreach (['engine' => 'package.json', 'api' => 'composer.json'] as $label => $manifestFile) {
            $file = $context->file($label);
            if ($file === null) {
                continue;
            }

            $kind = CheckContext::kindFor($label);
            $owned = $context->listingPackages($kind);
            $packageManifest = $file->manifest();

            if ($packageManifest === null) {
                $errors[] = "The {$label} tarball has no readable {$manifestFile} at its root or in its top-level folder.";

                continue;
            }

            $name = strtolower(trim((string) ($packageManifest['name'] ?? '')));
            if ($name === '') {
                $errors[] = "The {$label} tarball's {$manifestFile} has no name.";

                continue;
            }

            $names[$kind] = $name;

            if ($owned === []) {
                $errors[] = "The listing has no {$kind} package; add {$name} to the listing before releasing it.";
            } elseif (! in_array($name, $owned, true)) {
                $errors[] = "The {$label} tarball is {$name}, but the listing's {$kind} package is ".implode(', ', $owned).'.';
            }

            $reference = $context->manifest[$label] ?? null;
            if (ExtensionManifest::namesPackage($reference) && strtolower($reference) !== $name) {
                $errors[] = "extension.json names the {$label} package {$reference}, but the tarball is {$name}.";
            }

            $version = $packageManifest['version'] ?? null;
            if ($kind === ExtensionPackage::KIND_NPM && $version !== $context->release->version) {
                $errors[] = "{$manifestFile} version ".($version === null ? '(missing)' : $version).' does not match the release version '.$context->release->version.'.';
            }
            if ($kind === ExtensionPackage::KIND_COMPOSER && $version !== null && ltrim((string) $version, 'v') !== $context->release->version) {
                $errors[] = "composer.json version {$version} does not match the release version {$context->release->version}; remove it or keep them equal.";
            }
        }

        $context->meta['package_names'] = $names;

        return CheckResult::from(
            $errors,
            'Package names match the listing.',
            'Package names do not match the listing.',
            [],
            ['packages' => $names, 'listing' => [
                ExtensionPackage::KIND_NPM => $context->listingPackages(ExtensionPackage::KIND_NPM),
                ExtensionPackage::KIND_COMPOSER => $context->listingPackages(ExtensionPackage::KIND_COMPOSER),
            ]],
        );
    }
}
