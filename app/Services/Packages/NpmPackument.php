<?php

namespace App\Services\Packages;

use App\Models\ExtensionPackage;
use App\Models\Release;
use App\Support\Semver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Builds the npm packument (the full `GET /{name}` document) for an engine package from
 * its published, non-yanked releases. Version entries are the `package.json` captured when
 * the tarball was stored, with the authoritative name, version and `dist` laid over it.
 */
class NpmPackument
{
    /** package.json keys whose `{}` value must stay an object after the JSON round trip. */
    public const OBJECT_KEYS = [
        'dependencies', 'devDependencies', 'peerDependencies', 'optionalDependencies',
        'peerDependenciesMeta', 'bundleDependencies', 'engines', 'scripts', 'directories',
        'publishConfig', 'exports', 'bin', 'overrides',
    ];

    /** package.json keys the registry never republishes. */
    public const STRIPPED_KEYS = ['readme', 'readmeFilename', 'gitHead', 'private'];

    public static function tarballFilename(string $name, string $version): string
    {
        $basename = str_contains($name, '/') ? substr($name, strpos($name, '/') + 1) : $name;

        return $basename.'-'.$version.'.tgz';
    }

    public static function tarballUrl(string $name, string $version): string
    {
        return url('npm/'.$name.'/-/'.self::tarballFilename($name, $version));
    }

    public function build(ExtensionPackage $package): array
    {
        $extension = $package->extension;
        $releases = $package->servableReleases();
        $name = $package->name;

        $versions = [];
        $time = [];
        foreach ($releases as $release) {
            $versions[$release->version] = $this->version($name, $release);
            $time[$release->version] = $this->timestamp($release->published_at ?? $release->created_at);
        }

        $publishedAt = $releases->map(fn (Release $r) => $r->published_at ?? $r->created_at)->filter();
        $modifiedAt = $releases->map(fn (Release $r) => $r->updated_at)->push($extension->updated_at)->filter();

        $document = [
            '_id' => $name,
            'name' => $name,
            'description' => $extension->subtitle ?? data_get(end($versions) ?: [], 'description'),
            'dist-tags' => (object) $this->distTags($releases),
            'versions' => (object) $versions,
            'time' => (object) array_merge(array_filter([
                'created' => $this->timestamp($publishedAt->min()),
                'modified' => $this->timestamp($modifiedAt->max()),
            ]), $time),
        ];

        if ($extension->website_url) {
            $document['homepage'] = $extension->website_url;
        }
        if ($extension->description) {
            $document['readme'] = $extension->description;
        }

        return $document;
    }

    /**
     * `latest` = highest stable-channel release; `beta` = highest beta-channel release.
     * With no stable release yet, `latest` falls back to the highest beta so a bare
     * `npm install <name>` still resolves.
     *
     * @param  Collection<int, Release>  $releases
     */
    public function distTags(Collection $releases): array
    {
        $stable = Semver::max($releases->where('channel', Release::CHANNEL_STABLE)->pluck('version'));
        $beta = Semver::max($releases->where('channel', Release::CHANNEL_BETA)->pluck('version'));

        return array_filter(['latest' => $stable ?? $beta, 'beta' => $beta]);
    }

    protected function version(string $name, Release $release): array
    {
        $file = $release->engineTarball;
        $manifest = $file->manifest() ?? [];

        foreach (array_keys($manifest) as $key) {
            if (str_starts_with((string) $key, '_') || in_array($key, self::STRIPPED_KEYS, true)) {
                unset($manifest[$key]);
            }
        }
        foreach (self::OBJECT_KEYS as $key) {
            if (array_key_exists($key, $manifest) && $manifest[$key] === []) {
                $manifest[$key] = new \stdClass;
            }
        }

        return array_merge($manifest, [
            'name' => $name,
            'version' => $release->version,
            '_id' => $name.'@'.$release->version,
            'dependencies' => $manifest['dependencies'] ?? new \stdClass,
            'dist' => [
                'tarball' => self::tarballUrl($name, $release->version),
                'shasum' => $file->shasum,
                'integrity' => $file->integrity,
            ],
        ]);
    }

    protected function timestamp(?CarbonInterface $at): ?string
    {
        return $at?->copy()->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
