<?php

namespace App\Import;

use App\Models\Extension;
use App\Models\File;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Model;
use App\Models\Purchase;
use App\Models\Release;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

/**
 * `registry:import --verify`: compares the cloud tables with what the import wrote, after the
 * final run and before the console switches over. Three checks, any failure is drift:
 *
 * 1. Pending changes: the importer, dry-run against the imported target, would create or update
 *    nothing (an unchanged source re-imports as "unchanged" only).
 * 2. Counts: for extensions, releases, purchases, licences and installs, every source row has its
 *    target row (matched on `source_uuid`), except rows the importer skips (listed as anomalies).
 * 3. Sample: up to `$sample` random rows per table compared field by field with their target
 *    rows, tarball bytes included (SHA-256 of the source object, the stored copy and its record).
 */
class ImportVerifier
{
    /** source table => [target model, label] */
    public const MAPPINGS = [
        'extensions' => ['registry_extensions', Extension::class],
        'releases' => ['registry_extension_bundles', Release::class],
        'purchases' => ['registry_extension_purchases', Purchase::class],
        'licences' => ['registry_extension_purchases', Licence::class],
        'installs' => ['registry_extension_installs', Install::class],
    ];

    /** @var list<array{table: string, source: int, target: int, missing: int, skipped: int, drift: bool}> */
    public array $counts = [];

    /** @var array<string, array{created: int, updated: int}> */
    public array $pending = [];

    /** @var list<array{table: string, uuid: string, field: string, source: mixed, target: mixed}> */
    public array $mismatches = [];

    /** @var array<string, int> table => rows sampled */
    public array $sampled = [];

    public ?ImportReport $dryRun = null;

    public ?Instance $cloudInstance = null;

    public function __construct(
        protected Connection $source,
        protected string $sourceDisk,
        protected string $targetDisk,
        protected ?string $cloudHost = null,
        protected int $sample = 25,
        protected ?int $seed = null,
    ) {}

    public function run(): static
    {
        $this->dryRun = (new RegistryImporter($this->source, $this->sourceDisk, $this->targetDisk, true, $this->cloudHost))->run();

        foreach ($this->dryRun->counts as $table => $counts) {
            $created = (int) ($counts[ImportReport::CREATED] ?? 0);
            $updated = (int) ($counts[ImportReport::UPDATED] ?? 0);
            if ($created + $updated > 0) {
                $this->pending[$table] = ['created' => $created, 'updated' => $updated];
            }
        }

        $this->cloudInstance = Instance::query()->where('kind', Instance::KIND_CLOUD)->where('status', Instance::STATUS_ACTIVE)
            ->get()->first(fn (Instance $instance) => $instance->isPlatform());

        foreach (self::MAPPINGS as $table => [$sourceTable, $model]) {
            $this->compare($table, $sourceTable, $model);
        }

        return $this;
    }

    public function drift(): bool
    {
        return $this->pending !== []
            || $this->mismatches !== []
            || collect($this->counts)->contains('drift', true);
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function compare(string $table, string $sourceTable, string $model): void
    {
        $rows = $this->sourceRows($sourceTable);
        $targets = collect();
        foreach ($rows->keys()->chunk(500) as $chunk) {
            $targets = $targets->merge($this->query($model)->whereIn('source_uuid', $chunk->all())->get()->keyBy('source_uuid'));
        }

        $skipped = (int) ($this->dryRun->counts[$table][ImportReport::SKIPPED] ?? 0);
        if ($table === 'licences') {
            $skipped = (int) ($this->dryRun->counts['purchases'][ImportReport::SKIPPED] ?? 0);
        }

        $missing = $rows->count() - $targets->count();
        $this->counts[] = [
            'table' => $table,
            'source' => $rows->count(),
            'target' => $targets->count(),
            'missing' => $missing,
            'skipped' => $skipped,
            'drift' => $missing > $skipped,
        ];

        // Same rows for the same seed (default: today), so a rerun after a fix checks them again.
        $sample = $targets->keys()->shuffle(crc32(($this->seed ?? date('Ymd')).':'.$table))->take(max(0, $this->sample));
        $this->sampled[$table] = $sample->count();

        foreach ($sample as $uuid) {
            $this->{'check'.ucfirst($table)}($rows->get($uuid), $targets->get($uuid));
        }
    }

    protected function checkExtensions(object $row, Extension $extension): void
    {
        $hasBundles = $this->source->table('registry_extension_bundles')->where('extension_uuid', $row->uuid)->exists();
        $status = RegistryImporter::EXTENSION_STATUSES[$row->status ?? ''] ?? Extension::STATUS_DRAFT;
        if (! $hasBundles && trim((string) ($row->package_name ?? '')) === '') {
            $status = Extension::STATUS_DRAFT;
        }

        $publisher = ($row->publisher_uuid ?? null) ?: ($row->company_uuid ?? null);

        $this->expect('extensions', $row->uuid, 'name', $row->name, $extension->name);
        $this->expect('extensions', $row->uuid, 'status', $status, $extension->status);
        $this->expect('extensions', $row->uuid, 'deleted', $this->filled($row->deleted_at ?? null), $extension->trashed());
        $this->expect('extensions', $row->uuid, 'publisher', $publisher, $extension->organization()->withTrashed()->value('source_uuid'));
    }

    protected function checkReleases(object $bundle, Release $release): void
    {
        $meta = $this->json($bundle->meta ?? null) ?? [];
        $version = ltrim((string) (data_get($meta['extension.json'] ?? null, 'version') ?: ($bundle->version ?? null) ?: data_get($meta['package.json'] ?? null, 'version') ?: ''), 'vV');

        $this->expect('releases', $bundle->uuid, 'version', $version, $release->version);
        $this->expect('releases', $bundle->uuid, 'extension', $bundle->extension_uuid, $release->extension()->withTrashed()->value('source_uuid'));

        if ($release->status === Release::STATUS_YANKED && filled($release->meta['import_yank_reason'] ?? null)) {
            return;
        }

        $this->expect('releases', $bundle->uuid, 'status', RegistryImporter::RELEASE_STATUSES[$bundle->status ?? ''] ?? Release::STATUS_UPLOADED, $release->status);

        $file = File::withTrashed()->find($release->meta['source_bundle_file_uuid'] ?? null);
        $source = $this->source->table('files')->where('uuid', $bundle->bundle_uuid)->first();
        if (! $file || ! $source) {
            $this->expect('releases', $bundle->uuid, 'tarball', 'copied', 'no file record');

            return;
        }

        $sourceHash = $this->hash($this->sourceDisk, $source->path);
        $targetHash = $this->hash($file->disk, $file->path);
        $this->expect('releases', $bundle->uuid, 'tarball sha256 (source → copy)', $sourceHash, $targetHash);
        $this->expect('releases', $bundle->uuid, 'tarball sha256 (copy → record)', $targetHash, $file->checksum ? substr($file->checksum, 7) : null);
    }

    protected function checkPurchases(object $row, Purchase $purchase): void
    {
        $buyer = filled($row->purchaser_uuid ?? null) && filled($row->purchaser_type ?? null) ? $row->purchaser_uuid : ($row->company_uuid ?? null);

        $this->expect('purchases', $row->uuid, 'extension', $row->extension_uuid, $purchase->extension()->withTrashed()->value('source_uuid'));
        $this->expect('purchases', $row->uuid, 'buyer', $buyer, $purchase->buyer()->withTrashed()->value('source_uuid'));
        $this->expect('purchases', $row->uuid, 'deleted', $this->filled($row->deleted_at ?? null), $purchase->trashed());
        if (($row->locked_price ?? null) !== null) {
            $this->expect('purchases', $row->uuid, 'amount', (int) $row->locked_price, (int) $purchase->amount_total);
        }
    }

    protected function checkLicences(object $row, Licence $licence): void
    {
        $this->expect('licences', $row->uuid, 'kind', Licence::KIND_PAID, $licence->kind);
        $this->expect('licences', $row->uuid, 'status', $this->filled($row->deleted_at ?? null) ? Licence::STATUS_REVOKED : Licence::STATUS_ACTIVE, $licence->status);
        $this->expect('licences', $row->uuid, 'organization', $licence->purchase()->withTrashed()->first()?->buyer_organization_uuid, $licence->organization_uuid);
    }

    protected function checkInstalls(object $row, Install $install): void
    {
        $this->expect('installs', $row->uuid, 'instance', $this->cloudInstance?->uuid ?? 'the cloud instance', $install->instance_uuid);
        $this->expect('installs', $row->uuid, 'company', $row->company_uuid ?? null, $install->organization()->withTrashed()->value('source_uuid'));
        $this->expect('installs', $row->uuid, 'extension', $row->extension_uuid, Extension::withTrashed()->where('uuid', $install->extension_uuid)->value('source_uuid'));
        if ($this->filled($row->deleted_at ?? null)) {
            $this->expect('installs', $row->uuid, 'status', Install::STATUS_UNINSTALLED, $install->status);
        }
    }

    protected function expect(string $table, string $uuid, string $field, mixed $source, mixed $target): void
    {
        if ($source !== $target) {
            $this->mismatches[] = compact('table', 'uuid', 'field', 'source', 'target');
        }
    }

    /**
     * @return Collection<string, object>
     */
    protected function sourceRows(string $table): Collection
    {
        return $this->source->getSchemaBuilder()->hasTable($table)
            ? $this->source->table($table)->orderBy('id')->get()->keyBy('uuid')
            : collect();
    }

    /**
     * @param  class-string<Model>  $model
     */
    protected function query(string $model): Builder
    {
        return in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $model::withTrashed() : $model::query();
    }

    protected function hash(string $disk, ?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }

        try {
            $stream = Storage::disk($disk)->readStream($path);
        } catch (\Throwable) {
            return null;
        }
        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    protected function filled(mixed $date): bool
    {
        return $date !== null && $date !== '' && ! str_starts_with((string) $date, '0000-00-00');
    }

    protected function json(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;
        if (is_string($decoded)) {
            $decoded = json_decode($decoded, true);
        }

        return is_array($decoded) ? $decoded : null;
    }
}
