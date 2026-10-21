<?php

namespace App\Models;

use App\Traits\HasUuid;
use Database\Factories\InstallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Install telemetry reported by instances; the only source of install counts.
 */
class Install extends Model
{
    use HasFactory;
    use HasUuid;

    public const STATUS_INSTALLING = 'installing';

    public const STATUS_INSTALLED = 'installed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNINSTALLED = 'uninstalled';

    public const STATUSES = [self::STATUS_INSTALLING, self::STATUS_INSTALLED, self::STATUS_FAILED, self::STATUS_UNINSTALLED];

    protected $table = 'installs';

    protected $fillable = ['instance_uuid', 'extension_uuid', 'release_uuid', 'status', 'installed_by', 'error', 'started_at', 'finished_at'];

    protected $hidden = ['id'];

    protected $casts = ['started_at' => 'datetime', 'finished_at' => 'datetime'];

    protected static function newFactory(): InstallFactory
    {
        return InstallFactory::new();
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_uuid', 'uuid');
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_uuid', 'uuid');
    }
}
