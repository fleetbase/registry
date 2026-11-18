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

    /**
     * The newest published version of each extension that works with a console version, keyed
     * by extension uuid; extensions with no such release are left out. `$extensionUuids` limits
     * the extensions looked at (null: all).
     *
     * @param  iterable<string>|null  $extensionUuids
     * @return array<string, string>
     */
    public static function newestCompatible(string $consoleVersion, ?iterable $extensionUuids = null): array
    {
        return Release::query()
            ->where('status', Release::STATUS_PUBLISHED)
            ->when($extensionUuids !== null, fn ($query) => $query->whereIn('extension_uuid', collect($extensionUuids)->all()))
            ->get(['extension_uuid', 'version', 'compat'])
            ->groupBy('extension_uuid')
            ->map(fn (Collection $releases) => static::latest(
                $releases->filter(fn (Release $release) => static::satisfies($consoleVersion, data_get($release->compat, 'console')))
            )?->version)
            ->filter()
            ->all();
    }

    public static function releaseIsCompatible(Release $release, ?string $consoleVersion, ?string $apiVersion): bool
    {
        $compat = $release->compat ?? [];

        return static::satisfies($consoleVersion, data_get($compat, 'console'))
            && static::satisfies($apiVersion, data_get($compat, 'api'));
    }

    /** Host packages a prebuilt dist is pinned to (`dist.built_against`). */
    public const PINNED_HOST_PACKAGES = ['@fleetbase/ember-ui', '@fleetbase/ember-core'];

    /** Dist pin policies, as in ember-core's check-engine-compat and the bridge's PhpEngineCompat. */
    public const HOST_POLICIES = ['exact', 'minor', 'major'];

    /**
     * Whether a prebuilt dist built against `$builtAgainst` loads in a console whose host modules
     * are `$host` (package => version), under the same policies as ember-core's
     * check-engine-compat and the bridge's install-time check (D3): `exact` needs the same pinned
     * version, `minor` the same major and minor (the default), `major` the same major. Other
     * packages only need the same major. A version missing on either side is not compared.
     * Module lists are not stored on releases, so only versions are checked here; the installer
     * still runs the full check and rebuilds the engine from source when the dist does not fit.
     *
     * @param  array<string, mixed>  $builtAgainst
     * @param  array<string, mixed>  $host
     * @return array{compatible: bool, reasons: list<string>}
     */
    public static function hostModules(array $builtAgainst, array $host, string $policy = 'minor'): array
    {
        $reasons = [];

        foreach ($builtAgainst as $package => $built) {
            $b = Semver::parse((string) Semver::normalize(is_scalar($built) ? (string) $built : null));
            $h = Semver::parse((string) Semver::normalize(is_scalar($host[$package] ?? null) ? (string) $host[$package] : null));
            if ($b === null || $h === null) {
                continue;
            }

            $pinned = in_array($package, self::PINNED_HOST_PACKAGES, true);
            $ok = match (true) {
                $pinned && $policy === 'exact' => Semver::compare((string) $built, (string) $host[$package]) === 0,
                $pinned && $policy === 'minor' => $b['major'] === $h['major'] && $b['minor'] === $h['minor'],
                default => $b['major'] === $h['major'],
            };

            if (! $ok) {
                $reasons[] = "{$package} {$built} (built against) is not compatible with {$host[$package]} (console)";
            }
        }

        return ['compatible' => $reasons === [], 'reasons' => $reasons];
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
