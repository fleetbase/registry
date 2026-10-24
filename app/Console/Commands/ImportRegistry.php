<?php

namespace App\Console\Commands;

use App\Import\ImportReport;
use App\Import\ImportVerifier;
use App\Import\RegistryImporter;
use App\Import\SourceDatabase;
use App\Models\Event;
use Illuminate\Console\Command;

/**
 * Imports the cloud registry-bridge tables into the Registry Service.
 */
class ImportRegistry extends Command
{
    protected $signature = 'registry:import
        {--from= : mysql://user:pass@host:3306/db, sqlite:///path/to/file.sqlite, or a path to a .sql / .sql.gz dump}
        {--dry-run : Map everything and print counts and anomalies, then roll back; no files are copied}
        {--verify : Compare source and target after an import (pending changes, counts, a sample of rows and tarballs); exits 1 on drift}
        {--sample=25 : Rows per table compared field by field with --verify}
        {--seed= : Sample seed for --verify (default: today\'s date)}
        {--cloud-host= : Host the cloud API registers with; cloud installs attach to its instance (default: registry.cloud.host)}
        {--source-disk= : Filesystem disk holding the cloud `files` objects (default: registry.import.source_disk)}
        {--report= : Also write the full report, every anomaly included, as JSON to this path}
        {--examples=10 : Anomaly examples printed per type}';

    protected $description = 'Import extensions, releases, accounts, purchases and installs from the cloud registry-bridge tables';

    public function handle(): int
    {
        $from = (string) $this->option('from');
        if ($from === '') {
            $this->error('--from is required: a mysql:// or sqlite:// DSN, or a path to a SQL dump.');

            return self::INVALID;
        }

        if ($this->option('verify')) {
            return $this->verify(new SourceDatabase($from));
        }

        $dryRun = (bool) $this->option('dry-run');
        $sourceDisk = $this->option('source-disk') ?: config('registry.import.source_disk');
        $targetDisk = config('registry.storage.disk');
        $source = new SourceDatabase($from);

        $this->line(sprintf('<info>%s</info> from %s', $dryRun ? 'Dry run' : 'Importing', $source->describe()));
        $this->line("Files: {$sourceDisk} → {$targetDisk}");

        try {
            $connection = $source->open();
            $report = (new RegistryImporter($connection, $sourceDisk, $targetDisk, $dryRun, $this->cloudHost()))->run();
        } catch (\Throwable $e) {
            $this->error('Import failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $source->close();
        }

        $this->printReport($report, max(0, (int) $this->option('examples')));

        if (! $dryRun) {
            Event::record('registry.imported', null, null, [
                'from' => $source->describe(),
                'counts' => $report->counts,
                'anomalies' => array_map('count', $report->anomalies),
            ], '127.0.0.1');
        }

        if ($path = $this->option('report')) {
            file_put_contents($path, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $this->line("Full report written to {$path}");
        }

        $this->newLine();
        $this->line($dryRun ? '<comment>Dry run: nothing was written.</comment>' : '<info>Import complete.</info>');

        return self::SUCCESS;
    }

    /**
     * `--verify`: exit 0 when the target matches the source, 1 on drift (see ImportVerifier).
     */
    protected function verify(SourceDatabase $source): int
    {
        $this->line('<info>Verifying</info> '.$source->describe().' against this registry');

        try {
            $verifier = (new ImportVerifier(
                $source->open(),
                $this->option('source-disk') ?: config('registry.import.source_disk'),
                config('registry.storage.disk'),
                $this->cloudHost(),
                max(0, (int) $this->option('sample')),
                $this->option('seed') !== null ? (int) $this->option('seed') : null,
            ))->run();
        } catch (\Throwable $e) {
            $this->error('Verify failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $source->close();
        }

        $this->newLine();
        $this->line('<options=bold>Pending changes</> (a dry run against this registry)');
        $this->line($verifier->pending === [] ? '  none: the import is current' : '');
        if ($verifier->pending !== []) {
            $this->table(['Table', 'Would create', 'Would update'], collect($verifier->pending)->map(fn ($c, $t) => [$t, $c['created'], $c['updated']])->values()->all());
        }

        $this->line('<options=bold>Counts</> (target rows matched on source_uuid)');
        $this->table(['Table', 'Source', 'Target', 'Missing', 'Skipped by importer', 'Drift'], collect($verifier->counts)->map(fn ($c) => [
            $c['table'], $c['source'], $c['target'], $c['missing'], $c['skipped'], $c['drift'] ? '<error>yes</error>' : 'no',
        ])->all());

        $this->line('<options=bold>Sample</> ('.collect($verifier->sampled)->map(fn ($n, $t) => "{$t} {$n}")->implode(', ').')');
        if ($verifier->mismatches === []) {
            $this->line('  every sampled row matches');
        } else {
            $this->table(['Table', 'Source uuid', 'Field', 'Source', 'Target'], collect($verifier->mismatches)->map(fn ($m) => [
                $m['table'], $m['uuid'], $m['field'], json_encode($m['source']), json_encode($m['target']),
            ])->all());
        }

        $cloud = $verifier->cloudInstance;
        $this->line('Cloud instance: '.($cloud ? "{$cloud->public_id} ({$cloud->host}, ".($cloud->token_hash ? 'registered' : 'waiting for the cloud API to register').')' : 'none'));

        $this->newLine();
        if ($verifier->drift()) {
            $this->error('Drift between source and target.');

            return self::FAILURE;
        }

        $this->info('No drift.');

        return self::SUCCESS;
    }

    protected function cloudHost(): ?string
    {
        $host = $this->option('cloud-host') ?: config('registry.cloud.host');

        return is_string($host) && trim($host) !== '' ? trim($host) : null;
    }

    protected function printReport(ImportReport $report, int $examples): void
    {
        $this->newLine();
        $this->line('<options=bold>Source rows read</>');
        $this->table(['Table', 'Rows'], collect($report->source)->map(fn ($n, $t) => [$t, $n])->values()->all());

        $this->line('<options=bold>'.($report->dryRun ? 'Would write' : 'Written').'</>');
        $outcomes = [ImportReport::CREATED, ImportReport::UPDATED, ImportReport::UNCHANGED, ImportReport::SKIPPED];
        $this->table(
            array_merge(['Table'], array_map('ucfirst', $outcomes)),
            collect($report->counts)->sortKeys()->map(fn ($counts, $table) => array_merge([$table], array_map(fn ($o) => $counts[$o] ?? 0, $outcomes)))->values()->all()
        );

        $this->line('<options=bold>Anomalies</> ('.$report->anomalyCount().')');
        if ($report->anomalyCount() === 0) {
            $this->line('  none');

            return;
        }

        foreach ($report->anomalies as $type => $items) {
            $this->newLine();
            $this->line(sprintf('  <comment>%s</comment>: %d', ImportReport::ANOMALIES[$type] ?? $type, count($items)));
            foreach (array_slice($items, 0, $examples) as $item) {
                $this->line('    - '.collect($item)->map(fn ($v, $k) => $k.'='.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)))->implode(', '));
            }
            if (count($items) > $examples) {
                $this->line('    … '.(count($items) - $examples).' more (use --report for all)');
            }
        }
    }
}
