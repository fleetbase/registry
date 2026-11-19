<?php

namespace App\Releases\Checks;

use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\File;
use App\Models\Release;
use App\Releases\Archives\ArchiveInventory;
use App\Releases\Archives\ReleaseArchives;

/**
 * What every check reads: the release, its listing (with package names), the decoded
 * manifest and the inspected archives. Checks put values the pipeline keeps on the
 * release (`meta`) into `$meta`.
 */
class CheckContext
{
    /** Values merged into `releases.meta` after the checks ran (dot keys). */
    public array $meta = [];

    public function __construct(
        public readonly Release $release,
        public readonly Extension $extension,
        public readonly array $manifest,
        public readonly ReleaseArchives $archives,
    ) {}

    public function inventory(string $label): ?ArchiveInventory
    {
        return $this->archives->get($label);
    }

    public function file(string $label): ?File
    {
        return $this->release->archives()[$label] ?? null;
    }

    /**
     * Package names the listing owns for a kind (lowercase).
     *
     * @return list<string>
     */
    public function listingPackages(string $kind): array
    {
        return $this->extension->packages
            ->where('kind', $kind)
            ->pluck('name')
            ->map(fn ($name) => strtolower($name))
            ->values()
            ->all();
    }

    public static function kindFor(string $label): string
    {
        return $label === 'api' ? ExtensionPackage::KIND_COMPOSER : ExtensionPackage::KIND_NPM;
    }
}
