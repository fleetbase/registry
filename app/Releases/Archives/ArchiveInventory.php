<?php

namespace App\Releases\Archives;

/**
 * What one pass over an uploaded archive found: every entry (with its raw name, type,
 * declared size, link target, sha256 and native-executable kind), the totals, built-in
 * secret findings and the contents of small JSON files the checks read (`manifest.json`).
 */
class ArchiveInventory
{
    /** @var list<array{name: string, raw: string, type: string, size: int, link: string, sha256: ?string, executable: ?string, read: bool}> */
    public array $entries = [];

    public int $unpackedBytes = 0;

    /** Stopped early: too many entries or too many unpacked bytes. */
    public bool $truncated = false;

    /** Set when the file is not a readable tar / tar.gz archive. */
    public ?string $error = null;

    /** @var list<array{rule: string, description: string, file: string, line: int, match: string}> */
    public array $secrets = [];

    /** @var array<string, string> JSON files kept by name (see ArchiveInspector::KEEP_FILES) */
    public array $kept = [];

    public function __construct(
        public readonly string $label,
        public readonly string $path,
        public readonly int $compressedBytes,
        public readonly bool $gzipped,
    ) {}

    public function entryCount(): int
    {
        return count($this->entries);
    }

    /**
     * Regular file entries.
     *
     * @return list<array>
     */
    public function files(): array
    {
        return array_values(array_filter($this->entries, fn (array $entry) => ArchiveInspector::isRegularFile($entry['type'])));
    }

    public function file(string $name): ?array
    {
        foreach ($this->entries as $entry) {
            if ($entry['name'] === $name && ArchiveInspector::isRegularFile($entry['type'])) {
                return $entry;
            }
        }

        return null;
    }
}
