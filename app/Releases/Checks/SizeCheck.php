<?php

namespace App\Releases\Checks;

use App\Models\ReleaseCheck;

/**
 * Archive and content size limits (`registry.max_bundle_bytes`, `registry.releases.*`):
 * compressed size per archive, unpacked size, entry count and the largest single file.
 */
class SizeCheck implements Check
{
    public const LISTED = 10;

    public function name(): string
    {
        return ReleaseCheck::CHECK_SIZE;
    }

    public function run(CheckContext $context): CheckResult
    {
        $maxFile = (int) config('registry.releases.max_file_bytes');
        $maxUnpacked = (int) config('registry.releases.max_unpacked_bytes');
        $maxEntries = (int) config('registry.releases.max_entries');
        $errors = [];
        $sizes = [];

        foreach ($context->archives->all() as $label => $inventory) {
            $limit = $label === 'dist' ? (int) config('registry.releases.max_dist_bytes') : (int) config('registry.max_bundle_bytes');
            $sizes[$label] = ['compressed' => $inventory->compressedBytes, 'unpacked' => $inventory->unpackedBytes, 'entries' => $inventory->entryCount()];

            if ($inventory->compressedBytes > $limit) {
                $errors[] = "The {$label} archive is ".self::human($inventory->compressedBytes).'; the limit is '.self::human($limit).'.';
            }

            if ($inventory->truncated) {
                $errors[] = $inventory->entryCount() >= $maxEntries
                    ? "The {$label} archive has more than {$maxEntries} entries."
                    : "The {$label} archive unpacks to more than ".self::human($maxUnpacked).'.';
            }

            $large = array_filter($inventory->files(), fn (array $entry) => $entry['size'] > $maxFile);
            foreach (array_slice($large, 0, self::LISTED) as $entry) {
                $errors[] = "{$label}:{$entry['name']} is ".self::human($entry['size']).'; files are limited to '.self::human($maxFile).'.';
            }
            if (count($large) > self::LISTED) {
                $errors[] = 'and '.(count($large) - self::LISTED)." more files over the limit in {$label}.";
            }
        }

        return CheckResult::from($errors, 'Within the size limits.', 'Size limits exceeded.', [], ['sizes' => $sizes]);
    }

    public static function human(int $bytes): string
    {
        return $bytes >= 1048576 ? round($bytes / 1048576, 1).' MB' : ($bytes >= 1024 ? round($bytes / 1024, 1).' KB' : $bytes.' B');
    }
}
