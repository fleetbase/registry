<?php

namespace App\Import;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Opens the cloud database `registry:import --from=` points at, as a Laravel connection.
 *
 * Accepted forms:
 *   mysql://user:pass@host:3306/fleetbase   (also mariadb://)
 *   sqlite:///absolute/path/snapshot.sqlite
 *   /path/to/dump.sql or dump.sql.gz         (loaded into a temporary SQLite file)
 */
class SourceDatabase
{
    public const CONNECTION = 'registry_import_source';

    /** Tables the importer reads from the cloud database. */
    public const TABLES = [
        'registry_extensions', 'registry_extension_bundles', 'registry_extension_purchases',
        'registry_extension_installs', 'registry_users', 'registry_developer_accounts',
        'users', 'companies', 'files', 'categories',
    ];

    protected ?string $temporaryFile = null;

    /** @var array<string, int> rows loaded per table, when the source is a dump */
    public array $dumpRowCounts = [];

    public function __construct(public readonly string $from) {}

    public function open(): Connection
    {
        $config = $this->connectionConfig();

        Config::set('database.connections.'.self::CONNECTION, $config);
        DB::purge(self::CONNECTION);

        return DB::connection(self::CONNECTION);
    }

    public function describe(): string
    {
        if ($this->isDsn()) {
            return (string) preg_replace('#//([^:/@]+):[^@]*@#', '//$1:***@', $this->from);
        }

        return $this->from;
    }

    public function close(): void
    {
        DB::purge(self::CONNECTION);

        if ($this->temporaryFile && is_file($this->temporaryFile)) {
            @unlink($this->temporaryFile);
        }
        $this->temporaryFile = null;
    }

    protected function isDsn(): bool
    {
        return (bool) preg_match('#^(mysql|mariadb|sqlite)://#i', $this->from);
    }

    protected function connectionConfig(): array
    {
        if (preg_match('#^sqlite://(.+)$#i', $this->from, $m)) {
            $path = $m[1];
            if (! is_file($path)) {
                throw new RuntimeException("SQLite source not found: {$path}");
            }

            return ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => false];
        }

        if (preg_match('#^(mysql|mariadb)://#i', $this->from)) {
            $parts = parse_url($this->from);
            if ($parts === false || empty($parts['host']) || empty($parts['path']) || trim($parts['path'], '/') === '') {
                throw new RuntimeException('Expected a DSN like mysql://user:pass@host:3306/database.');
            }
            parse_str($parts['query'] ?? '', $query);

            return [
                'driver' => strtolower($parts['scheme']),
                'host' => $parts['host'],
                'port' => $parts['port'] ?? 3306,
                'database' => trim($parts['path'], '/'),
                'username' => isset($parts['user']) ? urldecode($parts['user']) : 'root',
                'password' => isset($parts['pass']) ? urldecode($parts['pass']) : '',
                'charset' => $query['charset'] ?? 'utf8mb4',
                'collation' => $query['collation'] ?? 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => false,
            ];
        }

        $this->temporaryFile = tempnam(sys_get_temp_dir(), 'registry-import-').'.sqlite';
        $this->dumpRowCounts = (new SqlDumpLoader(self::TABLES))->load($this->from, $this->temporaryFile);

        return ['driver' => 'sqlite', 'database' => $this->temporaryFile, 'prefix' => '', 'foreign_key_constraints' => false];
    }
}
