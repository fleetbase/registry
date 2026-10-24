<?php

namespace App\Services;

use App\Models\File;
use App\Support\Tarball;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The package store: objects on the registry disk (S3 in production, local in development)
 * with one `files` row each. Paths are `{prefix}/{file uuid}/{filename}` under the prefixes
 * in `registry.storage.prefixes`. Every stored object carries three digests:
 *
 *   checksum  sha256 hex, used internally and as the download ETag
 *   shasum    sha1 hex, npm `dist.shasum` and Composer `dist.shasum`
 *   integrity SRI sha512 (`sha512-…`), npm `dist.integrity`
 *
 * Package tarballs also keep their decoded `package.json` / `composer.json` in `meta`, so
 * metadata endpoints never open archives.
 */
class ReleaseStorage
{
    public const PREFIX_FOR_TYPE = [
        File::TYPE_ENGINE_TARBALL => 'tarballs',
        File::TYPE_API_TARBALL => 'tarballs',
        File::TYPE_ENGINE_DIST => 'dist',
        File::TYPE_ICON => 'icons',
        File::TYPE_SCREENSHOT => 'screenshots',
    ];

    /** Manifest kept in `files.meta` for each package tarball type. */
    public const MANIFEST_FOR_TYPE = [
        File::TYPE_ENGINE_TARBALL => 'package.json',
        File::TYPE_API_TARBALL => 'composer.json',
    ];

    public const CHUNK_BYTES = 1024 * 1024;

    public function __construct(protected ?string $diskName = null) {}

    public function diskName(): string
    {
        return $this->diskName ?? config('registry.storage.disk', 'local');
    }

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function prefix(string $type): string
    {
        $key = self::PREFIX_FOR_TYPE[$type] ?? throw new \InvalidArgumentException("Unknown file type [{$type}].");

        return trim(config("registry.storage.prefixes.{$key}", $key), '/');
    }

    /**
     * Copy a local file into the store and record it.
     *
     * @param  array  $attributes  extra `files` columns (name, original_filename, uploader_account_uuid, mime, meta)
     */
    public function store(string $sourcePath, string $type, ?Model $subject = null, array $attributes = []): File
    {
        if (! is_file($sourcePath) || ! is_readable($sourcePath)) {
            throw new \InvalidArgumentException("Source file [{$sourcePath}] is not readable.");
        }

        $digests = static::digests($sourcePath);
        $filename = static::safeFilename($attributes['name'] ?? basename($sourcePath));
        $uuid = (string) Str::uuid();
        $path = $this->prefix($type).'/'.$uuid.'/'.$filename;

        $meta = $attributes['meta'] ?? [];
        if ($manifestName = self::MANIFEST_FOR_TYPE[$type] ?? null) {
            try {
                $manifest = Tarball::manifest($sourcePath, $manifestName);
            } catch (\RuntimeException) {
                // Not a readable archive; stored anyway so the release checks can report it.
                $manifest = null;
            }
            if ($manifest !== null) {
                $meta[Str::snake(str_replace('.', '_', $manifestName))] = $manifest;
            }
        }

        $stream = fopen($sourcePath, 'rb');
        try {
            if ($this->disk()->writeStream($path, $stream) === false) {
                throw new \RuntimeException("Unable to write [{$path}] to disk [{$this->diskName()}].");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return (new File)->forceFill(array_merge([
            'original_filename' => basename($sourcePath),
            'mime' => static::detectMime($sourcePath, $type),
        ], $attributes, [
            'uuid' => $uuid,
            'subject_type' => $subject?->getMorphClass(),
            'subject_uuid' => $subject?->getKey(),
            'type' => $type,
            'disk' => $this->diskName(),
            'path' => $path,
            'name' => $filename,
            'size' => $digests['size'],
            'checksum' => $digests['sha256'],
            'shasum' => $digests['sha1'],
            'integrity' => $digests['integrity'],
            'meta' => $meta ?: null,
        ]))->saveInstance();
    }

    /**
     * Store raw bytes (fixtures, generated archives).
     */
    public function storeContents(string $contents, string $type, string $filename, ?Model $subject = null, array $attributes = []): File
    {
        $tmp = tempnam(sys_get_temp_dir(), 'reg');
        try {
            file_put_contents($tmp, $contents);

            return $this->store($tmp, $type, $subject, ['name' => $filename, 'original_filename' => $filename] + $attributes);
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * sha256 + sha1 hex digests, the SRI sha512 integrity string and the byte size.
     *
     * @return array{sha256: string, sha1: string, integrity: string, size: int}
     */
    public static function digests(string $path): array
    {
        $contexts = ['sha256' => hash_init('sha256'), 'sha1' => hash_init('sha1'), 'sha512' => hash_init('sha512')];
        $handle = fopen($path, 'rb');
        $size = 0;
        while (! feof($handle)) {
            $chunk = fread($handle, self::CHUNK_BYTES);
            if ($chunk === false) {
                break;
            }
            $size += strlen($chunk);
            foreach ($contexts as $context) {
                hash_update($context, $chunk);
            }
        }
        fclose($handle);

        return [
            'sha256' => hash_final($contexts['sha256']),
            'sha1' => hash_final($contexts['sha1']),
            'integrity' => 'sha512-'.base64_encode(hash_final($contexts['sha512'], true)),
            'size' => $size,
        ];
    }

    public function exists(File $file): bool
    {
        return Storage::disk($file->disk)->exists($file->path);
    }

    /**
     * Recompute the sha256 of the stored object and compare it to the recorded checksum.
     */
    public function verify(File $file): bool
    {
        $stream = Storage::disk($file->disk)->readStream($file->path);
        if (! is_resource($stream)) {
            return false;
        }

        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_equals((string) $file->checksum, hash_final($context));
    }

    public function delete(File $file): void
    {
        Storage::disk($file->disk)->delete($file->path);
        $file->delete();
    }

    /**
     * Stream a stored object. Answers `If-None-Match` with 304 (the ETag is the sha256),
     * and re-hashes the bytes as they go out so a corrupted object is logged.
     */
    public function download(File $file, Request $request, array $headers = []): Response
    {
        $headers = array_merge([
            'Content-Type' => $file->mime ?: 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="'.addcslashes((string) $file->name, '"\\').'"',
            'X-Checksum-Sha256' => $file->checksum,
        ], $headers);

        $notModified = new Response;
        $notModified->setEtag((string) $file->checksum);
        if ($notModified->isNotModified($request)) {
            $notModified->headers->add(array_intersect_key($headers, array_flip(['Cache-Control', 'Vary'])));

            return $notModified;
        }

        $disk = Storage::disk($file->disk);
        $stream = $disk->readStream($file->path);
        if (! is_resource($stream)) {
            Log::error('Package store object missing', ['file' => $file->public_id, 'disk' => $file->disk, 'path' => $file->path]);
            abort(404, 'Package file not found.');
        }

        $response = new StreamedResponse(function () use ($stream, $file) {
            $context = hash_init('sha256');
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK_BYTES);
                if ($chunk === false) {
                    break;
                }
                hash_update($context, $chunk);
                echo $chunk;
                flush();
            }
            fclose($stream);

            $actual = hash_final($context);
            if ($file->checksum && ! hash_equals($file->checksum, $actual)) {
                Log::critical('Package store checksum mismatch on download', [
                    'file' => $file->public_id, 'expected' => $file->checksum, 'actual' => $actual,
                ]);
            }
        }, 200, $headers);

        $response->setEtag((string) $file->checksum);
        if ($file->size > 0) {
            $response->headers->set('Content-Length', (string) $file->size);
        }

        return $response;
    }

    public static function safeFilename(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name);

        return trim($name, '.-') ?: 'file';
    }

    protected static function detectMime(string $path, string $type): string
    {
        $magic = (string) file_get_contents($path, false, null, 0, 262);

        return match (true) {
            str_starts_with($magic, "\x1f\x8b") => 'application/gzip',
            strlen($magic) >= 262 && substr($magic, 257, 5) === 'ustar' => 'application/x-tar',
            in_array($type, [File::TYPE_ICON, File::TYPE_SCREENSHOT], true) => (function_exists('mime_content_type') ? mime_content_type($path) : null) ?: 'application/octet-stream',
            default => 'application/octet-stream',
        };
    }
}
