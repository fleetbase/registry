<?php

namespace App\Support;

/**
 * Semantic Versioning 2.0.0 parsing and precedence, enough to order releases and pick
 * dist-tags. Ranges and constraints are out of scope.
 */
class Semver
{
    public const PATTERN = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/';

    /**
     * Versions Composer's VersionParser accepts from a release: x.y.z with an optional
     * alpha/beta/RC/patch suffix and dotted numbers (`1.2.0-beta.1`, `2.0.0-RC2`).
     */
    public const COMPOSER_PATTERN = '/^v?\d+\.\d+\.\d+(?:-?(?:stable|beta|b|RC|alpha|a|patch|pl|p)(?:[.-]?\d+)*)?$/i';

    /**
     * @return array{major: int, minor: int, patch: int, prerelease: list<string>}|null
     */
    public static function parse(string $version): ?array
    {
        if (! preg_match(self::PATTERN, trim($version), $m)) {
            return null;
        }

        return [
            'major' => (int) $m[1],
            'minor' => (int) $m[2],
            'patch' => (int) $m[3],
            'prerelease' => isset($m[4]) && $m[4] !== '' ? explode('.', $m[4]) : [],
        ];
    }

    public static function valid(string $version): bool
    {
        return self::parse($version) !== null;
    }

    public static function isComposerCompatible(string $version): bool
    {
        return preg_match(self::COMPOSER_PATTERN, $version) === 1;
    }

    public static function isPrerelease(string $version): bool
    {
        return (self::parse($version)['prerelease'] ?? []) !== [];
    }

    /**
     * -1, 0 or 1 by SemVer precedence. Invalid versions sort below valid ones.
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse($a);
        $pb = self::parse($b);

        if ($pa === null || $pb === null) {
            return ($pa !== null) <=> ($pb !== null) ?: strcmp($a, $b) <=> 0;
        }

        foreach (['major', 'minor', 'patch'] as $part) {
            if ($pa[$part] !== $pb[$part]) {
                return $pa[$part] <=> $pb[$part];
            }
        }

        // A version without a prerelease has higher precedence than one with.
        if ($pa['prerelease'] === [] || $pb['prerelease'] === []) {
            return ($pa['prerelease'] === []) <=> ($pb['prerelease'] === []);
        }

        $count = max(count($pa['prerelease']), count($pb['prerelease']));
        for ($i = 0; $i < $count; $i++) {
            $x = $pa['prerelease'][$i] ?? null;
            $y = $pb['prerelease'][$i] ?? null;
            if ($x === null || $y === null) {
                return $x === null ? -1 : 1;
            }

            $xNumeric = ctype_digit($x);
            $yNumeric = ctype_digit($y);
            $cmp = match (true) {
                $xNumeric && $yNumeric => (int) $x <=> (int) $y,
                $xNumeric => -1,
                $yNumeric => 1,
                default => strcmp($x, $y) <=> 0,
            };
            if ($cmp !== 0) {
                return $cmp;
            }
        }

        return 0;
    }

    /**
     * Sort ascending by precedence.
     *
     * @param  list<string>  $versions
     * @return list<string>
     */
    public static function sort(array $versions): array
    {
        usort($versions, [self::class, 'compare']);

        return $versions;
    }

    /**
     * @param  iterable<string>  $versions
     */
    public static function max(iterable $versions): ?string
    {
        $max = null;
        foreach ($versions as $version) {
            if ($max === null || self::compare($version, $max) > 0) {
                $max = $version;
            }
        }

        return $max;
    }
}
