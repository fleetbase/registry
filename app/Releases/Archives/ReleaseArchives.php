<?php

namespace App\Releases\Archives;

use App\Models\Release;
use App\Releases\Secrets\SecretRules;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Local copies of a release's stored archives (`engine`, `api`, `dist`), each inspected
 * once. With `$mirror`, safe regular files are also written under a temporary directory
 * (`<dir>/<label>/<path>`) for gitleaks. `cleanup()` removes everything.
 */
class ReleaseArchives
{
    /** @var array<string, ArchiveInventory> */
    protected array $inventories = [];

    protected array $temporary = [];

    public ?string $mirrorDir = null;

    public static function open(Release $release, bool $mirror = false): static
    {
        $archives = new static;
        $inspector = new ArchiveInspector(new SecretRules);

        if ($mirror) {
            $archives->mirrorDir = sys_get_temp_dir().'/release-'.Str::uuid();
            mkdir($archives->mirrorDir, 0700, true);
        }

        foreach ($release->archives() as $label => $file) {
            $local = tempnam(sys_get_temp_dir(), 'rel');
            $archives->temporary[] = $local;

            $stream = Storage::disk($file->disk)->readStream($file->path);
            if (! is_resource($stream)) {
                $inventory = new ArchiveInventory($label, $local, 0, false);
                $inventory->error = 'The stored file is missing.';
                $archives->inventories[$label] = $inventory;

                continue;
            }

            $out = fopen($local, 'wb');
            stream_copy_to_stream($stream, $out);
            fclose($out);
            fclose($stream);

            $archives->inventories[$label] = $inspector->inspect($local, $label, $mirror ? $archives->mirrorDir.'/'.$label : null);
        }

        return $archives;
    }

    public function get(string $label): ?ArchiveInventory
    {
        return $this->inventories[$label] ?? null;
    }

    /**
     * @return array<string, ArchiveInventory>
     */
    public function all(): array
    {
        return $this->inventories;
    }

    public function cleanup(): void
    {
        foreach ($this->temporary as $path) {
            @unlink($path);
        }
        $this->temporary = [];

        if ($this->mirrorDir !== null && is_dir($this->mirrorDir)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->mirrorDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->mirrorDir);
        }
    }
}
