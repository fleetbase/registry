<?php

namespace App\Support;

use App\Models\Release;
use Composer\Semver\Comparator;
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

        $version = static::normalizeVersion($version);
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
     * Highest-versioned release in the collection; unparseable versions sort last.
     */
    public static function latest(Collection $releases): ?Release
    {
        return $releases
            ->filter(fn (Release $release) => static::normalizeVersion($release->version) !== null)
            ->sort(fn (Release $a, Release $b) => static::compare($b->version, $a->version))
            ->first();
    }

    /**
     * -1, 0 or 1 like the spaceship operator.
     */
    public static function compare(string $a, string $b): int
    {
        if (Comparator::equalTo($a, $b)) {
            return 0;
        }

        return Comparator::greaterThan($a, $b) ? 1 : -1;
    }

    public static function isNewer(?string $candidate, ?string $current): bool
    {
        if ($candidate === null || static::normalizeVersion($candidate) === null) {
            return false;
        }

        if ($current === null || static::normalizeVersion($current) === null) {
            return true;
        }

        return static::compare($candidate, $current) > 0;
    }

    /**
     * The version without a leading `v`, or null when it is not a semver version.
     */
    public static function normalizeVersion(?string $version): ?string
    {
        $version = ltrim(trim((string) $version), 'vV');

        return preg_match('/^\d+(\.\d+){0,2}(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/', $version) === 1 ? $version : null;
    }
}
