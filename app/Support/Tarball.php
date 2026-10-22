<?php

namespace App\Support;

/**
 * Minimal streaming reader for tar and tar.gz archives (ustar, GNU long names, pax paths).
 *
 * Used to pull `package.json` / `composer.json` out of uploaded package archives without
 * extracting them to disk or going through PharData. Entries are never written anywhere,
 * so path traversal and symlink entries are harmless here; the release checks reject them.
 */
class Tarball
{
    public const BLOCK = 512;

    /**
     * Visit each entry header. The visitor receives (name, type, size, raw name, link
     * target) and returns true to receive the entry's contents through `$onContents`, or
     * null to stop iterating. `name` is normalised (no leading `./` or `/`); the raw name is
     * exactly what the archive holds, for the path traversal checks.
     *
     * @param  \Closure(string $name, string $type, int $size, string $rawName, string $linkTarget): ?bool  $visitor
     * @param  \Closure(string $name, string $contents): void|null  $onContents
     */
    public static function walk(string $path, \Closure $visitor, ?\Closure $onContents = null, int $maxEntryBytes = 1024 * 1024): void
    {
        // gzopen reads plain (uncompressed) files transparently.
        $handle = @gzopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open archive [{$path}].");
        }

        try {
            $longName = null;
            $longLink = null;
            $paxPath = null;
            $paxLink = null;

            while (true) {
                $header = self::readExactly($handle, self::BLOCK);
                if ($header === null || trim($header, "\0") === '') {
                    return;
                }

                if (! self::validChecksum($header)) {
                    throw new \RuntimeException('Not a tar archive (bad header checksum).');
                }

                $type = $header[156] === "\0" ? '0' : $header[156];
                $size = self::parseSize(substr($header, 124, 12));
                $name = self::cString(substr($header, 0, 100));
                if (substr($header, 257, 5) === 'ustar') {
                    $prefix = self::cString(substr($header, 345, 155));
                    $name = $prefix !== '' ? $prefix.'/'.$name : $name;
                }

                $padded = (int) (ceil($size / self::BLOCK) * self::BLOCK);

                // Metadata entries describe the next header (`g` describes the whole archive).
                if (in_array($type, ['L', 'K', 'x', 'g'], true)) {
                    $data = self::readExactly($handle, $padded) ?? '';
                    $data = substr($data, 0, $size);
                    if ($type === 'L') {
                        $longName = self::cString($data);
                    } elseif ($type === 'K') {
                        $longLink = self::cString($data);
                    } elseif ($type === 'x') {
                        if (preg_match('/^\d+ path=(.*)$/m', $data, $m)) {
                            $paxPath = $m[1];
                        }
                        if (preg_match('/^\d+ linkpath=(.*)$/m', $data, $m)) {
                            $paxLink = $m[1];
                        }
                    }

                    continue;
                }

                $rawName = $paxPath ?? $longName ?? $name;
                $linkTarget = $paxLink ?? $longLink ?? self::cString(substr($header, 157, 100));
                $longName = $longLink = $paxPath = $paxLink = null;
                $name = ltrim(preg_replace('#^(\./)+#', '', $rawName), '/');

                $wants = $visitor($name, $type, $size, $rawName, $linkTarget);
                if ($wants === null) {
                    return;
                }

                if ($wants && $onContents && $size <= $maxEntryBytes) {
                    $data = self::readExactly($handle, $padded) ?? '';
                    $onContents($name, substr($data, 0, $size));
                } elseif ($padded > 0) {
                    self::skip($handle, $padded);
                }
            }
        } finally {
            gzclose($handle);
        }
    }

    /**
     * Decode a manifest (`package.json`, `composer.json`) at the archive root or inside a
     * single top-level folder (npm packs into `package/`), preferring the shallowest.
     */
    public static function manifest(string $path, string $filename): ?array
    {
        $candidates = [];

        self::walk(
            $path,
            fn (string $name, string $type) => $type === '0' && preg_match('#^([^/]+/)?'.preg_quote($filename, '#').'$#', $name) === 1,
            function (string $name, string $contents) use (&$candidates) {
                $candidates[substr_count($name, '/')] ??= $contents;
            },
        );

        if ($candidates === []) {
            return null;
        }

        ksort($candidates);
        $decoded = json_decode(reset($candidates), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Entry names in archive order (for tests and diagnostics).
     */
    public static function names(string $path): array
    {
        $names = [];
        self::walk($path, function (string $name) use (&$names) {
            $names[] = $name;

            return false;
        });

        return $names;
    }

    protected static function readExactly($handle, int $length): ?string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $chunk = gzread($handle, $length - strlen($buffer));
            if ($chunk === false || $chunk === '') {
                return $buffer === '' ? null : str_pad($buffer, $length, "\0");
            }
            $buffer .= $chunk;
        }

        return $buffer;
    }

    protected static function skip($handle, int $length): void
    {
        while ($length > 0) {
            $chunk = gzread($handle, min($length, 65536));
            if ($chunk === false || $chunk === '') {
                return;
            }
            $length -= strlen($chunk);
        }
    }

    /**
     * The header checksum: the sum of all header bytes with the checksum field read as
     * spaces. Old tars summed signed bytes, so either sum is accepted.
     */
    protected static function validChecksum(string $header): bool
    {
        $field = trim(self::cString(substr($header, 148, 8)));
        if ($field === '' || preg_match('/^[0-7]+$/', $field) !== 1) {
            return false;
        }

        $blank = substr_replace($header, '        ', 148, 8);
        $unsigned = array_sum(unpack('C*', $blank));
        $signed = array_sum(unpack('c*', $blank));
        $expected = octdec($field);

        return $expected === $unsigned || $expected === $signed;
    }

    protected static function parseSize(string $field): int
    {
        // Base-256 sizes (high bit set) are only used for entries over 8 GiB.
        if ((ord($field[0]) & 0x80) !== 0) {
            throw new \RuntimeException('Archive entry too large.');
        }

        return (int) octdec(trim(self::cString($field)) ?: '0');
    }

    protected static function cString(string $value): string
    {
        $nul = strpos($value, "\0");

        return $nul === false ? $value : substr($value, 0, $nul);
    }
}
