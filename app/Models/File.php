<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Stored object on a disk: tarballs, prebuilt dist archives, icons and screenshots.
 */
class File extends Model
{
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const TYPE_ENGINE_TARBALL = 'engine_tarball';

    public const TYPE_API_TARBALL = 'api_tarball';

    public const TYPE_ENGINE_DIST = 'engine_dist';

    public const TYPE_ICON = 'icon';

    public const TYPE_SCREENSHOT = 'screenshot';

    protected $table = 'files';

    protected string $publicIdType = 'file';

    protected $fillable = [
        'uploader_account_uuid', 'subject_type', 'subject_uuid', 'type', 'disk', 'path', 'name',
        'original_filename', 'size', 'mime', 'checksum', 'shasum', 'integrity', 'meta',
    ];

    protected $hidden = ['id', 'disk', 'path'];

    protected $casts = ['size' => 'integer', 'meta' => 'array'];

    /**
     * Decoded `package.json` (engine tarballs) or `composer.json` (API tarballs) captured
     * by `ReleaseStorage` when the archive was stored.
     */
    public function manifest(): ?array
    {
        return match ($this->type) {
            self::TYPE_ENGINE_TARBALL => $this->meta['package_json'] ?? null,
            self::TYPE_API_TARBALL => $this->meta['composer_json'] ?? null,
            default => null,
        };
    }

    public function subject(): MorphTo
    {
        return $this->morphTo('subject', 'subject_type', 'subject_uuid', 'uuid');
    }

    /**
     * Public URL for icons and screenshots. Package tarballs are served through the
     * package endpoints instead so licence checks apply.
     */
    public function getUrlAttribute(): ?string
    {
        if (! in_array($this->type, [self::TYPE_ICON, self::TYPE_SCREENSHOT], true)) {
            return null;
        }

        try {
            return Storage::disk($this->disk)->url($this->path);
        } catch (\Throwable) {
            return null;
        }
    }
}
