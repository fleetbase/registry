<?php

namespace App\Models;

use App\Exceptions\ReservedPackageNamespaceException;
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

    protected static function booted(): void
    {
        static::saving(function (ExtensionPackage $package) {
            $package->name = strtolower(trim((string) $package->name));

            if ($package->isDirty(['name', 'kind', 'extension_uuid'])) {
                $package->assertNamespaceAllowed();
            }
        });
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    /**
     * The scope or vendor: `fleetbase` for `@fleetbase/x` and `fleetbase/x`; null for an
     * unscoped npm name.
     */
    public static function namespaceOf(string $kind, string $name): ?string
    {
        $name = strtolower($name);

        if ($kind === self::KIND_NPM) {
            return preg_match('#^@([^/]+)/#', $name, $m) ? $m[1] : null;
        }

        return str_contains($name, '/') ? strstr($name, '/', true) : null;
    }

    public static function isReservedNamespace(?string $namespace): bool
    {
        return $namespace !== null && in_array($namespace, array_map('strtolower', config('registry.reserved_scopes', [])), true);
    }

    /**
     * Reserved namespaces may only be owned by an extension of a staff organization.
     *
     * @throws ReservedPackageNamespaceException
     */
    public function assertNamespaceAllowed(): void
    {
        $namespace = static::namespaceOf((string) $this->kind, (string) $this->name);
        if (! static::isReservedNamespace($namespace)) {
            return;
        }

        $owner = Extension::withTrashed()->find($this->extension_uuid)?->organization()->first();
        if ($owner?->is_staff !== true) {
            throw ReservedPackageNamespaceException::for($this->name, $this->kind === self::KIND_NPM ? '@'.$namespace : $namespace);
        }
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
