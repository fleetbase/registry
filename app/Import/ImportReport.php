<?php

namespace App\Import;

/**
 * Counts per target table and anomalies found while mapping cloud rows.
 */
class ImportReport
{
    public const CREATED = 'created';

    public const UPDATED = 'updated';

    public const UNCHANGED = 'unchanged';

    public const SKIPPED = 'skipped';

    /** @var array<string, int> rows read per source table */
    public array $source = [];

    /** @var array<string, array<string, int>> target table => outcome => count */
    public array $counts = [];

    /** @var array<string, list<array<string, mixed>>> anomaly type => items */
    public array $anomalies = [];

    /** @var array<string, string> anomaly type => human description */
    public const ANOMALIES = [
        'extension_without_packages' => 'Extensions without package names or bundles',
        'tarball_missing' => 'Bundles whose tarball path is missing (imported as yanked)',
        'duplicate_package_name' => 'Duplicate package names (first listing keeps the name)',
        'user_without_email' => 'Users without emails (not imported)',
        'merged_account' => 'Accounts merged by email across cloud users and developer accounts',
        'missing_reference' => 'Rows that reference a missing company, user, extension or developer account',
        'bundle_without_version' => 'Bundles without a version (not imported)',
        'duplicate_version' => 'Bundles that repeat a version of the same extension (older ones not imported)',
        'slug_renamed' => 'Extension or organization slugs changed to stay unique',
        'unknown_status' => 'Unknown statuses (mapped to a safe default)',
        'subscription' => 'Subscription pricing or purchases (imported as one-time)',
        'install_without_licence' => 'Installs with no usable licence (install imported, no activation)',
        'duplicate_install' => 'Several live installs of one extension by one company (older ones marked uninstalled)',
        'duplicate_purchase' => 'Repeat purchases of one extension by one buyer (each imported with its own licence)',
        'icon_missing' => 'Icons or screenshots whose file could not be retrieved',
        'username_taken' => 'Usernames already taken by another account (left empty)',
        'conflict' => 'Rows that conflict with records created in the service (not imported)',
    ];

    public function __construct(public readonly bool $dryRun = false) {}

    public function count(string $table, string $outcome, int $by = 1): void
    {
        $this->counts[$table][$outcome] = ($this->counts[$table][$outcome] ?? 0) + $by;
    }

    public function anomaly(string $type, array $item): void
    {
        $this->anomalies[$type][] = $item;
    }

    public function anomalyCount(?string $type = null): int
    {
        if ($type !== null) {
            return count($this->anomalies[$type] ?? []);
        }

        return array_sum(array_map('count', $this->anomalies));
    }

    public function total(string $table, string $outcome): int
    {
        return $this->counts[$table][$outcome] ?? 0;
    }

    public function toArray(): array
    {
        return [
            'dry_run' => $this->dryRun,
            'source' => $this->source,
            'counts' => $this->counts,
            'anomalies' => collect($this->anomalies)->map(fn ($items, $type) => [
                'description' => self::ANOMALIES[$type] ?? $type,
                'count' => count($items),
                'items' => $items,
            ])->all(),
        ];
    }
}
