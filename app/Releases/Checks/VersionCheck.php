<?php

namespace App\Releases\Checks;

use App\Models\Release;
use App\Models\ReleaseCheck;
use App\Support\Semver;

/**
 * The version is SemVer, greater than every release already out (published or yanked) on
 * the same channel, and in a form Composer accepts when the release carries an api package.
 */
class VersionCheck implements Check
{
    public function name(): string
    {
        return ReleaseCheck::CHECK_VERSION;
    }

    public function run(CheckContext $context): CheckResult
    {
        $release = $context->release;
        $version = $release->version;
        $errors = [];
        $warnings = [];

        if (! Semver::valid($version) || str_starts_with($version, 'v')) {
            return CheckResult::fail('The version is not SemVer.', ["{$version} is not a SemVer version (MAJOR.MINOR.PATCH[-prerelease])."]);
        }

        $previous = Semver::max(
            Release::query()
                ->where('extension_uuid', $release->extension_uuid)
                ->where('channel', $release->channel)
                ->whereKeyNot($release->getKey())
                ->released()
                ->pluck('version')
                ->filter(fn ($v) => Semver::normalize($v) !== null)
        );

        if ($previous !== null && Semver::compare($version, $previous) <= 0) {
            $errors[] = "{$version} is not greater than {$previous}, the last {$release->channel} release.";
        }

        if ($context->file('api') !== null && ! Semver::isComposerCompatible($version)) {
            $errors[] = "Composer cannot install {$version}: use a -alpha, -beta or -RC prerelease (for example 1.2.0-beta.1).";
        }

        $prerelease = Semver::isPrerelease($version);
        if ($release->channel === Release::CHANNEL_STABLE && $prerelease) {
            $warnings[] = "{$version} is a prerelease on the stable channel; npm's `latest` tag will point at it.";
        } elseif ($release->channel === Release::CHANNEL_BETA && ! $prerelease) {
            $warnings[] = "{$version} has no prerelease suffix but is on the beta channel.";
        }

        return CheckResult::from(
            $errors,
            $previous === null ? "{$version} is the first {$release->channel} release." : "{$version} follows {$previous}.",
            'The version cannot be released.',
            $warnings,
            ['previous' => $previous],
        );
    }
}
