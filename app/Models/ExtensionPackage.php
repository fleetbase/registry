<?php

namespace App\Models;

use App\Support\Semver;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * npm or Composer package name owned by a listing; unique per registry.
 */
class ExtensionPackage extends Model
{
    use HasUuid;

    public const KIND_NPM = 'npm';

    public const KIND_COMPOSER = 'composer';

    public const KINDS = [self::KIND_NPM, self::KIND_COMPOSER];

    protected $table = 'extension_packages';

    protected $fillable = ['extension_uuid', 'kind', 'name'];

    protected $hidden = ['id'];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    /**
     * The package a client asked for, if its listing is servable: not deleted and not
     * suspended. Names are matched lowercase, as npm and Composer require them.
     */
    public static function resolve(string $kind, string $name): ?static
    {
        return static::query()
            ->where('kind', $kind)
            ->where('name', strtolower($name))
            ->whereHas('extension', fn (Builder $q) => $q->where('status', '!=', Extension::STATUS_SUSPENDED))
            ->with('extension.price')
            ->first();
    }

    /**
     * Release column and relation holding this package's archive.
     */
    public function fileRelation(): string
    {
        return $this->kind === self::KIND_NPM ? 'engineTarball' : 'apiTarball';
    }

    /**
     * Published releases that carry this package's archive and a valid SemVer version,
     * ascending by precedence. Yanked releases are excluded.
     *
     * @return Collection<int, Release>
     */
    public function servableReleases(): Collection
    {
        $relation = $this->fileRelation();

        return $this->extension->releases()
            ->published()
            ->whereNotNull($relation === 'engineTarball' ? 'engine_tarball_file_uuid' : 'api_tarball_file_uuid')
            ->with($relation)
            ->get()
            ->filter(fn (Release $release) => $release->{$relation} !== null && Semver::valid($release->version))
            ->sort(fn (Release $a, Release $b) => Semver::compare($a->version, $b->version))
            ->values();
    }

    /**
     * A release whose archive may be downloaded. Yanked releases stay downloadable so
     * existing lockfiles keep installing; they are only hidden from metadata.
     */
    public function downloadableRelease(string $version): ?Release
    {
        return $this->extension->releases()
            ->whereIn('status', [Release::STATUS_PUBLISHED, Release::STATUS_YANKED])
            ->where('version', $version)
            ->with($this->fileRelation())
            ->first();
    }
}
