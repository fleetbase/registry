<?php

namespace App\Models;

use App\Traits\HasUuid;
use Database\Factories\InstallFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Install telemetry reported by instances; the only source of install counts and of the
 * publisher's install analytics. A row is one install attempt: `installed_at` is set when it
 * succeeds, and it ends with `uninstalled_at` (removed) or `replaced_at` (a newer install on the
 * same instance took over, which then carries `previous_release_uuid`: an update).
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

    protected $fillable = [
        'instance_uuid', 'organization_uuid', 'extension_uuid', 'release_uuid', 'previous_release_uuid', 'status', 'installed_by', 'error',
        'started_at', 'finished_at', 'installed_at', 'uninstalled_at', 'replaced_at',
    ];

    protected $hidden = ['id'];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'installed_at' => 'datetime',
        'uninstalled_at' => 'datetime',
        'replaced_at' => 'datetime',
    ];

    protected static function newFactory(): InstallFactory
    {
        return InstallFactory::new();
    }

    /**
     * An install is for its instance's organization unless it says otherwise (the cloud instance
     * installs for the company it acts for).
     */
    protected static function booted(): void
    {
        static::creating(function (Install $install) {
            if ($install->organization_uuid === null && $install->instance_uuid !== null) {
                $install->organization_uuid = Instance::withTrashed()->where('uuid', $install->instance_uuid)->value('organization_uuid');
            }
        });
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_uuid', 'uuid');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'release_uuid', 'uuid');
    }

    public function previousRelease(): BelongsTo
    {
        return $this->belongsTo(Release::class, 'previous_release_uuid', 'uuid');
    }

    /**
     * Installs running now: what the catalog's `installs_count` and the active-instance figures
     * count (one installed row per instance and extension).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_INSTALLED);
    }
}
