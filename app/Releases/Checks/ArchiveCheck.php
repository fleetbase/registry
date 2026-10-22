<?php

namespace App\Releases\Checks;

use App\Models\ReleaseCheck;
use App\Releases\Archives\ArchiveInspector;

/**
 * Archive structure: each upload is a readable tar (the engine gzipped, as npm requires)
 * holding only regular files and directories. Rejects path traversal (absolute paths,
 * `..`, drive letters, backslashes), symlinks and hard links, devices and FIFOs, forbidden
 * file extensions and native executables (by magic number, whatever their name).
 */
class ArchiveCheck implements Check
{
    public const LISTED = 25;

    public function name(): string
    {
        return ReleaseCheck::CHECK_ARCHIVE;
    }

    public function run(CheckContext $context): CheckResult
    {
        $forbidden = array_map('strtolower', (array) config('registry.releases.forbidden_extensions', []));
        $errors = [];

        foreach ($context->archives->all() as $label => $inventory) {
            if ($inventory->error !== null) {
                $errors[] = "The {$label} upload is not a readable tar or tar.gz archive: {$inventory->error}";

                continue;
            }

            if ($label === 'engine' && ! $inventory->gzipped) {
                $errors[] = 'The engine tarball must be gzip-compressed (npm pack output).';
            }

            if ($inventory->files() === []) {
                $errors[] = "The {$label} archive contains no files.";
            }

            $problems = [];
            foreach ($inventory->entries as $entry) {
                $where = "{$label}:{$entry['raw']}";

                if (! ArchiveInspector::isSafePath($entry['raw'])) {
                    $problems[] = "{$where} escapes the package root (absolute path, `..`, drive letter or backslash).";
                }

                $problem = match ($entry['type']) {
                    '0', '7', '5' => null,
                    '1' => "{$where} is a hard link (to {$entry['link']}); links are not allowed.",
                    '2' => "{$where} is a symbolic link (to {$entry['link']}); links are not allowed.",
                    '3', '4' => "{$where} is a device file.",
                    '6' => "{$where} is a FIFO.",
                    default => "{$where} has an unsupported tar entry type `{$entry['type']}`.",
                };
                if ($problem !== null) {
                    $problems[] = $problem;
                }

                if (! ArchiveInspector::isRegularFile($entry['type'])) {
                    continue;
                }

                $extension = strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION));
                if ($extension !== '' && in_array($extension, $forbidden, true)) {
                    $problems[] = "{$where}: .{$extension} files are not allowed.";
                } elseif ($entry['executable'] !== null) {
                    $problems[] = "{$where} is a native binary ({$entry['executable']}).";
                }
            }

            array_push($errors, ...array_slice($problems, 0, self::LISTED));
            if (count($problems) > self::LISTED) {
                $errors[] = 'and '.(count($problems) - self::LISTED)." more problems in {$label}.";
            }
        }

        return CheckResult::from($errors, 'Archives contain only regular files inside the package.', 'Archives contain disallowed entries.');
    }
}
