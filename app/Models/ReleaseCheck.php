<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReleaseCheck extends Model
{
    use HasUuid;

    public const CHECK_MANIFEST = 'manifest';

    public const CHECK_PACKAGE_NAMES = 'package_names';

    public const CHECK_SECRETS_SCAN = 'secrets_scan';

    public const CHECK_COMPAT = 'compat';

    public const CHECK_SIZE = 'size';

    public const CHECK_VERSION = 'version';

    /** Entry types, path traversal, symlinks and forbidden file types. */
    public const CHECK_ARCHIVE = 'archive';

    /** The prebuilt engine release (`dist`) manifest from ember-core's `build-engine-release`. */
    public const CHECK_DIST = 'dist';

    /** Engine build smoke test; reserved for Phase 3. */
    public const CHECK_BUILD = 'build';

    public const CHECKS = [
        self::CHECK_MANIFEST, self::CHECK_COMPAT, self::CHECK_PACKAGE_NAMES, self::CHECK_VERSION,
        self::CHECK_SIZE, self::CHECK_ARCHIVE, self::CHECK_SECRETS_SCAN, self::CHECK_DIST, self::CHECK_BUILD,
    ];

    protected $table = 'release_checks';

    protected $fillable = ['release_uuid', 'check', 'passed', 'output'];

    protected $hidden = ['id'];

    protected $casts = ['passed' => 'boolean', 'output' => 'array'];

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_uuid', 'uuid');
    }
}
