<?php

namespace App\Import;

use PDO;
use RuntimeException;

/**
 * Loads selected tables from a MySQL dump (`mysqldump`, `.sql` or `.sql.gz`) into a
 * SQLite file so the importer can query them like a live database, without a MySQL
 * server. Every column is stored as TEXT; the importer casts what it reads.
 *
 * Handles mysqldump's extended inserts (one statement per line, any length), dumps
 * that put one tuple per line (phpMyAdmin, TablePlus), explicit column lists,
 * `REPLACE`/`INSERT IGNORE`, string escapes, `_binary`/charset introducers and hex
 * literals. Statements for other tables are skipped without being parsed.
 */
class SqlDumpLoader
{
    /** @var array<string, list<string>> */
    protected array $columns = [];

    /** @var array<string, int> */
    protected array $rowCounts = [];

    protected PDO $pdo;

    /**
     * @param  list<string>  $tables  tables to keep
     */
    public function __construct(protected array $tables) {}

    /**
     * @return array<string, int> rows loaded per table
     */
    public function load(string $dumpPath, string $sqlitePath): array
    {
        if (! is_file($dumpPath) || ! is_readable($dumpPath)) {
            throw new RuntimeException("SQL dump not found or not readable: {$dumpPath}");
        }

        $handle = fopen(str_ends_with($dumpPath, '.gz') ? 'compress.zlib://'.$dumpPath : $dumpPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Could not open SQL dump: {$dumpPath}");
        }

        $this->pdo = new PDO('sqlite:'.$sqlitePath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('PRAGMA journal_mode = OFF');
        $this->pdo->exec('PRAGMA synchronous = OFF');
        $this->pdo->beginTransaction();

        try {
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([^`"\s(]+)[`"]?/i', $line, $match)) {
                    $statement = $this->readUntilTerminated($handle, $line);
                    if ($this->wanted($match[1])) {
                        $this->defineTable($match[1], $this->parseCreateColumns($statement), true);
                    }

                    continue;
                }

                if (preg_match('/^(?:INSERT|REPLACE)(?:\s+IGNORE)?\s+INTO\s+[`"]?([^`"\s(]+)[`"]?/i', $line, $match) && $this->wanted($match[1])) {
                    $this->loadInsert($handle, $match[1], $line);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        } finally {
            fclose($handle);
        }

        return $this->rowCounts;
    }

    protected function wanted(string $table): bool
    {
        return in_array($table, $this->tables, true);
    }

    /**
     * @param  resource  $handle
     */
    protected function readUntilTerminated($handle, string $statement): string
    {
        $line = $statement;
        while (! str_ends_with(rtrim($line), ';')) {
            $line = fgets($handle);
            if ($line === false) {
                break;
            }
            $statement .= $line;
        }

        return $statement;
    }

    /**
     * @return list<string>
     */
    protected function parseCreateColumns(string $statement): array
    {
        $columns = [];
        foreach (preg_split('/\R/', $statement) as $line) {
            if (preg_match('/^\s*[`"]((?:[^`"]|``)+)[`"]\s+\w/', $line, $match)) {
                $columns[] = str_replace('``', '`', $match[1]);
            }
        }

        if ($columns === []) {
            throw new RuntimeException('Could not read the columns of a CREATE TABLE statement in the dump.');
        }

        return $columns;
    }

    /**
     * @param  list<string>  $columns
     */
    protected function defineTable(string $table, array $columns, bool $replace): void
    {
        if (isset($this->columns[$table]) && ! $replace) {
            return;
        }

        $quoted = implode(', ', array_map(fn ($c) => $this->quote($c).' TEXT', $columns));
        $this->pdo->exec('DROP TABLE IF EXISTS '.$this->quote($table));
        $this->pdo->exec('CREATE TABLE '.$this->quote($table)." ({$quoted})");

        $this->columns[$table] = $columns;
        $this->rowCounts[$table] = 0;
    }

    /**
     * @param  resource  $handle
     */
    protected function loadInsert($handle, string $table, string $statement): void
    {
        while (true) {
            try {
                [$columns, $rows] = $this->parseInsert($statement);
                break;
            } catch (IncompleteStatement) {
                $next = fgets($handle);
                if ($next === false) {
                    throw new RuntimeException("The dump ends inside an INSERT statement for `{$table}`.");
                }
                $statement .= $next;
            }
        }

        if (! isset($this->columns[$table])) {
            if ($columns === null) {
                throw new RuntimeException("The dump has rows for `{$table}` but no CREATE TABLE statement or column list for it.");
            }
            $this->defineTable($table, $columns, false);
        }

        $tableColumns = $this->columns[$table];
        $insertColumns = $columns ?? $tableColumns;
        $placeholders = implode(', ', array_fill(0, count($insertColumns), '?'));
        $insert = $this->pdo->prepare(
            'INSERT INTO '.$this->quote($table).' ('.implode(', ', array_map(fn ($c) => $this->quote($c), $insertColumns)).") VALUES ({$placeholders})"
        );

        foreach ($rows as $row) {
            if (count($row) !== count($insertColumns)) {
                throw new RuntimeException("A row for `{$table}` has ".count($row).' values but '.count($insertColumns).' columns.');
            }
            $insert->execute($row);
            $this->rowCounts[$table]++;
        }
    }

    /**
     * Parse one INSERT statement into its column list (null when implicit) and rows.
     *
     * @return array{0: list<string>|null, 1: list<list<string|null>>}
     *
     * @throws IncompleteStatement when the statement continues on the next line
     */
    public function parseInsert(string $sql): array
    {
        if (! preg_match('/^(?:INSERT|REPLACE)(?:\s+IGNORE)?\s+INTO\s+[`"]?[^`"\s(]+[`"]?\s*(?:\(([^)]*)\))?\s*VALUES\s*/i', $sql, $match)) {
            if (! preg_match('/\bVALUES\b/i', $sql)) {
                throw new IncompleteStatement;
            }

            throw new RuntimeException('Unrecognised INSERT statement in the dump.');
        }

        $columns = isset($match[1]) && $match[1] !== ''
            ? array_map(fn ($c) => trim($c, " \t\n\r`\""), explode(',', $match[1]))
            : null;

        $i = strlen($match[0]);
        $len = strlen($sql);
        $rows = [];

        while (true) {
            $i = $this->skipWhitespace($sql, $i, $len);
            if ($sql[$i] !== '(') {
                throw new RuntimeException('Expected "(" in an INSERT statement at offset '.$i.'.');
            }
            $i++;

            $row = [];
            while (true) {
                $i = $this->skipWhitespace($sql, $i, $len);
                [$value, $i] = $this->parseValue($sql, $i, $len);
                $row[] = $value;

                $i = $this->skipWhitespace($sql, $i, $len);
                if ($sql[$i] === ',') {
                    $i++;

                    continue;
                }
                if ($sql[$i] === ')') {
                    $i++;
                    break;
                }

                throw new RuntimeException('Unexpected "'.$sql[$i].'" in an INSERT statement at offset '.$i.'.');
            }
            $rows[] = $row;

            $i = $this->skipWhitespace($sql, $i, $len);
            if ($sql[$i] === ',') {
                $i++;

                continue;
            }
            if ($sql[$i] === ';') {
                return [$columns, $rows];
            }

            throw new RuntimeException('Unexpected "'.$sql[$i].'" after a row in an INSERT statement at offset '.$i.'.');
        }
    }

    protected function skipWhitespace(string $sql, int $i, int $len): int
    {
        $i += strspn($sql, " \t\r\n", $i);
        if ($i >= $len) {
            throw new IncompleteStatement;
        }

        return $i;
    }

    /**
     * @return array{0: string|null, 1: int}
     */
    protected function parseValue(string $sql, int $i, int $len): array
    {
        $char = $sql[$i];

        if ($char === "'" || $char === '"') {
            return $this->parseString($sql, $i, $len);
        }

        // Charset introducers: _binary '…', _utf8mb4'…'
        if ($char === '_' && preg_match('/\G_[a-z0-9]+\s*(?=[\'"])/Ai', $sql, $m, 0, $i)) {
            return $this->parseString($sql, $i + strlen($m[0]), $len);
        }

        $token = substr($sql, $i, strcspn($sql, ",) \t\r\n", $i));
        if ($i + strlen($token) >= $len) {
            throw new IncompleteStatement;
        }
        $next = $i + strlen($token);

        if (strcasecmp($token, 'NULL') === 0) {
            return [null, $next];
        }
        if (strcasecmp($token, 'TRUE') === 0 || strcasecmp($token, 'FALSE') === 0) {
            return [strcasecmp($token, 'TRUE') === 0 ? '1' : '0', $next];
        }
        if (preg_match('/^0x([0-9a-f]*)$/i', $token, $m)) {
            return [hex2bin(strlen($m[1]) % 2 ? '0'.$m[1] : $m[1]), $next];
        }
        if (preg_match("/^b'([01]*)'$/i", $token, $m)) {
            return [(string) bindec($m[1] ?: '0'), $next];
        }

        return [$token, $next];
    }

    /**
     * @return array{0: string, 1: int}
     */
    protected function parseString(string $sql, int $i, int $len): array
    {
        static $escapes = ['0' => "\0", "'" => "'", '"' => '"', 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A", '\\' => '\\', '%' => '\\%', '_' => '\\_'];

        $quote = $sql[$i];
        $i++;
        $value = '';

        while (true) {
            $chunk = strcspn($sql, $quote.'\\', $i);
            $value .= substr($sql, $i, $chunk);
            $i += $chunk;

            if ($i >= $len) {
                throw new IncompleteStatement;
            }

            if ($sql[$i] === '\\') {
                if ($i + 1 >= $len) {
                    throw new IncompleteStatement;
                }
                $value .= $escapes[$sql[$i + 1]] ?? $sql[$i + 1];
                $i += 2;

                continue;
            }

            // Doubled quote inside the string.
            if ($i + 1 < $len && $sql[$i + 1] === $quote) {
                $value .= $quote;
                $i += 2;

                continue;
            }

            return [$value, $i + 1];
        }
    }

    protected function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
