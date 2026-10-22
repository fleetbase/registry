<?php

namespace App\Services\Packages;

use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Release;
use App\Support\Semver;
use Illuminate\Database\Eloquent\Builder;

/**
 * Composer v2 repository documents built from published releases: the root
 * `packages.json` and the minified `p2/{vendor}/{package}.json` version lists.
 */
class ComposerMetadata
{
    public const MINIFIED = 'composer/2.0';

    /** composer.json keys carried into repository metadata (root-only keys are left out). */
    public const KEYS = [
        'name', 'description', 'keywords', 'homepage', 'readme', 'type', 'license', 'authors', 'support',
        'funding', 'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'autoload',
        'autoload-dev', 'include-path', 'target-dir', 'extra', 'bin', 'abandoned',
    ];

    /** Keys whose `{}` value must stay an object after the JSON round trip. */
    public const OBJECT_KEYS = [
        'require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest', 'autoload', 'autoload-dev',
        'extra', 'support',
    ];

    public static function metadataUrlTemplate(): string
    {
        return url('composer/p2').'/%package%.json';
    }

    public static function distUrl(string $name, string $version): string
    {
        return url('composer/dist/'.$name.'/'.$version.'.tar');
    }

    /**
     * GET /composer/packages.json. `available-packages` lists every Composer package with
     * at least one published release, so Composer never asks for names we cannot serve.
     */
    public function root(): array
    {
        $names = ExtensionPackage::query()
            ->where('kind', ExtensionPackage::KIND_COMPOSER)
            ->whereHas('extension', fn (Builder $q) => $q
                ->where('status', '!=', Extension::STATUS_SUSPENDED)
                ->whereHas('releases', fn (Builder $r) => $r->published()->whereNotNull('api_tarball_file_uuid')))
            ->orderBy('name')
            ->pluck('name')
            ->all();

        return [
            'packages' => new \stdClass,
            'metadata-url' => self::metadataUrlTemplate(),
            'available-packages' => $names,
        ];
    }

    /**
     * GET /composer/p2/{vendor}/{package}.json (newest first, minified) and the `~dev`
     * variant, which is always empty: the registry has no branch versions.
     */
    public function versions(ExtensionPackage $package, bool $dev = false): array
    {
        $versions = $dev ? [] : $package->servableReleases()
            ->filter(fn (Release $release) => Semver::isComposerCompatible($release->version))
            ->reverse()
            ->map(fn (Release $release) => $this->version($package, $release))
            ->values()
            ->all();

        return [
            'packages' => [$package->name => self::minify($versions)],
            'minified' => self::MINIFIED,
        ];
    }

    protected function version(ExtensionPackage $package, Release $release): array
    {
        $file = $release->apiTarball;
        $manifest = array_intersect_key($file->manifest() ?? [], array_flip(self::KEYS));

        foreach (self::OBJECT_KEYS as $key) {
            if (array_key_exists($key, $manifest) && $manifest[$key] === []) {
                $manifest[$key] = new \stdClass;
            }
        }

        return array_merge(['type' => 'library'], $manifest, [
            'name' => $package->name,
            'version' => $release->version,
            'time' => ($release->published_at ?? $release->created_at)?->copy()->utc()->toAtomString(),
            'dist' => [
                'type' => 'tar',
                'url' => self::distUrl($package->name, $release->version),
                'shasum' => $file->shasum,
                'reference' => $file->checksum,
            ],
        ]);
    }

    /**
     * Composer\MetadataMinifier\MetadataMinifier::minify: each entry after the first holds
     * only the keys that changed from the previous one, with `__unset` for removed keys.
     */
    public static function minify(array $versions): array
    {
        $minified = [];
        $previous = null;

        foreach ($versions as $version) {
            if ($previous === null) {
                $minified[] = $previous = $version;

                continue;
            }

            $entry = [];
            foreach ($version as $key => $value) {
                if (! array_key_exists($key, $previous) || $previous[$key] !== $value) {
                    $entry[$key] = $value;
                    $previous[$key] = $value;
                }
            }
            foreach (array_keys($previous) as $key) {
                if (! array_key_exists($key, $version)) {
                    $entry[$key] = '__unset';
                    unset($previous[$key]);
                }
            }

            $minified[] = $entry;
        }

        return $minified;
    }
}
