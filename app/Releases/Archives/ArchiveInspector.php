<?php

namespace App\Releases\Archives;

use App\Releases\Secrets\SecretRules;
use App\Support\Tarball;

/**
 * Reads an archive once without extracting it: records every entry for the size and
 * archive checks, hashes regular files, sniffs native executables, runs the built-in
 * secrets ruleset over text files and keeps the JSON files later checks need. Optionally
 * mirrors safe regular files into a directory for an external scanner (gitleaks).
 *
 * Walking stops at `registry.releases.max_entries` entries or once the declared sizes pass
 * `registry.releases.max_unpacked_bytes`, so a crafted archive cannot keep it busy.
 */
class ArchiveInspector
{
    /** Basenames whose contents are kept in `ArchiveInventory::$kept`. */
    public const KEEP_FILES = ['manifest.json', 'package.json', 'composer.json'];

    public const KEEP_MAX_BYTES = 5 * 1024 * 1024;

    /** Magic numbers of native executables and JVM classes. */
    public const EXECUTABLE_MAGIC = [
        "\x7fELF" => 'ELF executable',
        "\xfe\xed\xfa\xce" => 'Mach-O executable',
        "\xfe\xed\xfa\xcf" => 'Mach-O executable',
        "\xce\xfa\xed\xfe" => 'Mach-O executable',
        "\xcf\xfa\xed\xfe" => 'Mach-O executable',
        "\xca\xfe\xba\xbe" => 'Mach-O universal binary or Java class',
        'MZ' => 'Windows (PE) executable',
    ];

    public function __construct(protected ?SecretRules $secrets = null) {}

    public static function isRegularFile(string $type): bool
    {
        return $type === '0' || $type === '7';
    }

    public function inspect(string $path, string $label, ?string $mirrorDir = null): ArchiveInventory
    {
        $head = (string) @file_get_contents($path, false, null, 0, 2);
        $inventory = new ArchiveInventory($label, $path, (int) @filesize($path), $head === "\x1f\x8b");

        $maxEntries = (int) config('registry.releases.max_entries', 20000);
        $maxUnpacked = (int) config('registry.releases.max_unpacked_bytes', 300 * 1024 * 1024);
        $maxFile = (int) config('registry.releases.max_file_bytes', 25 * 1024 * 1024);
        $current = null;

        try {
            Tarball::walk(
                $path,
                function (string $name, string $type, int $size, string $raw = '', string $link = '') use ($inventory, $maxEntries, $maxUnpacked, $maxFile, &$current) {
                    if ($inventory->entryCount() >= $maxEntries || $inventory->unpackedBytes + $size > $maxUnpacked) {
                        $inventory->truncated = true;
                        $inventory->unpackedBytes += $size;

                        return null;
                    }

                    $read = self::isRegularFile($type) && $size <= $maxFile;
                    $inventory->entries[] = [
                        'name' => $name, 'raw' => $raw !== '' ? $raw : $name, 'type' => $type, 'size' => $size,
                        'link' => $link, 'sha256' => null, 'executable' => null, 'read' => $read,
                    ];
                    $inventory->unpackedBytes += $size;
                    $current = array_key_last($inventory->entries);

                    if ($this->secrets !== null && self::isRegularFile($type) && ! $read) {
                        array_push($inventory->secrets, ...$this->secrets->scan($name, null));
                    }

                    return $read;
                },
                function (string $name, string $contents) use ($inventory, $mirrorDir, &$current) {
                    $entry = &$inventory->entries[$current];
                    $entry['sha256'] = hash('sha256', $contents);
                    $entry['executable'] = self::executableKind($contents);

                    if (in_array(basename($name), self::KEEP_FILES, true) && strlen($contents) <= self::KEEP_MAX_BYTES) {
                        $inventory->kept[$name] = $contents;
                    }

                    if ($this->secrets !== null) {
                        array_push($inventory->secrets, ...$this->secrets->scan($name, $contents));
                    }

                    if ($mirrorDir !== null && self::isSafePath($entry['raw'])) {
                        $target = rtrim($mirrorDir, '/').'/'.$name;
                        @mkdir(dirname($target), 0700, true);
                        file_put_contents($target, $contents);
                    }
                },
                $maxFile,
            );
        } catch (\Throwable $e) {
            $inventory->error = $e->getMessage();
        }

        return $inventory;
    }

    /**
     * A relative path with no `..` segment, drive letter or backslash.
     */
    public static function isSafePath(string $raw): bool
    {
        if ($raw === '' || str_starts_with($raw, '/') || str_contains($raw, '\\') || preg_match('/^[A-Za-z]:/', $raw) === 1) {
            return false;
        }

        return ! in_array('..', explode('/', $raw), true);
    }

    public static function executableKind(string $contents): ?string
    {
        foreach (self::EXECUTABLE_MAGIC as $magic => $kind) {
            if (str_starts_with($contents, $magic)) {
                // "MZ" alone is too short to trust; require the PE header offset to point at "PE\0\0".
                if ($magic === 'MZ') {
                    $offset = strlen($contents) >= 64 ? unpack('V', substr($contents, 60, 4))[1] : null;
                    if ($offset === null || substr($contents, $offset, 4) !== "PE\0\0") {
                        continue;
                    }
                }

                return $kind;
            }
        }

        return null;
    }
}
