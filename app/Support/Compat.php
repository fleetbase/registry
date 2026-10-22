<?php

namespace App\Support;

use App\Models\Release;
use Composer\Semver\Constraint\Constraint;
use Composer\Semver\VersionParser;
use Illuminate\Support\Collection;

/**
 * Version compatibility between releases (`releases.compat`) and instances.
 *
 * `compat` holds `{"console": "<range>", "api": "<range>"}` using npm/Composer range
 * syntax (`>=0.5.0`, `^1.6`, `>=1.0 <2.0`, `1.x || 2.x`), evaluated with composer/semver.
 * Rules: a side with no range accepts any version; an instance that does not report a
 * version (or reports one that is not semver) is not constrained on that side; a range
 * that does not parse never matches, so a broken manifest cannot be offered as an update.
 * Version ordering is `Semver`'s; this class only evaluates ranges.
 */
class Compat
{
    public static function satisfies(?string $version, ?string $range): bool
    {
        $range = trim((string) $range);
        if ($range === '' || $range === '*') {
            return true;
        }

        try {
            $constraint = (new VersionParser)->parseConstraints($range);
        } catch (\UnexpectedValueException) {
            return false;
        }

        $version = Semver::normalize($version);
        if ($version === null) {
            return true;
        }

        $parser = new VersionParser;

        return $constraint->matches(new Constraint('==', $parser->normalize($version)));
    }

    public static function releaseIsCompatible(Release $release, ?string $consoleVersion, ?string $apiVersion): bool
    {
        $compat = $release->compat ?? [];

        return static::satisfies($consoleVersion, data_get($compat, 'console'))
            && static::satisfies($apiVersion, data_get($compat, 'api'));
    }

    /**
     * Whether a range parses with composer/semver. Empty means "any version".
     */
    public static function validRange(?string $range): bool
    {
        $range = trim((string) $range);
        if ($range === '' || $range === '*') {
            return true;
        }

        try {
            (new VersionParser)->parseConstraints($range);

            return true;
        } catch (\UnexpectedValueException) {
            return false;
        }
    }

    /**
     * Highest-versioned release in the collection; unparseable versions are left out.
     */
    public static function latest(Collection $releases): ?Release
    {
        return $releases
            ->filter(fn (Release $release) => Semver::normalize($release->version) !== null)
            ->sort(fn (Release $a, Release $b) => Semver::compare($b->version, $a->version))
            ->first();
    }
}
