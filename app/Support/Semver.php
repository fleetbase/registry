<?php

namespace App\Support;

/**
 * Semantic Versioning 2.0.0 parsing and precedence: the one version comparator in the
 * service. Orders releases, picks dist-tags, decides updates and enforces that a new
 * release is greater than the last one on its channel. Ranges live in `Compat`.
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

    /**
     * The version as strict SemVer without a leading `v`, padding a missing minor or patch
     * with zeros (`v1.2` → `1.2.0`), or null when it is not a version at all (`dev-main`).
     */
    public static function normalize(?string $version): ?string
    {
        $version = ltrim(trim((string) $version), 'vV');

        if (preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?((?:-|\+).*)?$/', $version, $m) === 1 && ($m[3] ?? '') === '') {
            $version = $m[1].'.'.(($m[2] ?? '') !== '' ? $m[2] : '0').'.0'.($m[4] ?? '');
        }

        return self::parse($version) !== null ? $version : null;
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
     * -1, 0 or 1 by SemVer precedence, after `normalize()`. Build metadata is ignored.
     * Invalid versions sort below valid ones.
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::parse(self::normalize($a) ?? $a);
        $pb = self::parse(self::normalize($b) ?? $b);

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
     * True when the candidate is a version and has higher precedence than the current one;
     * anything beats no (or an unparseable) current version.
     */
    public static function isNewer(?string $candidate, ?string $current): bool
    {
        if ($candidate === null || self::normalize($candidate) === null) {
            return false;
        }

        if ($current === null || self::normalize($current) === null) {
            return true;
        }

        return self::compare($candidate, $current) > 0;
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
