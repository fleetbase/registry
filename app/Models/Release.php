<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Database\Factories\ReleaseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One row per version. A published release is immutable.
 */
class Release extends Model
{
    use HasFactory;
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const CHANNEL_STABLE = 'stable';

    public const CHANNEL_BETA = 'beta';

    public const CHANNELS = [self::CHANNEL_STABLE, self::CHANNEL_BETA];

    public const STATUS_UPLOADED = 'uploaded';

    public const STATUS_CHECKING = 'checking';

    public const STATUS_FAILED_CHECKS = 'failed_checks';

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_YANKED = 'yanked';

    public const STATUSES = [
        self::STATUS_UPLOADED, self::STATUS_CHECKING, self::STATUS_FAILED_CHECKS, self::STATUS_SUBMITTED,
        self::STATUS_IN_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED, self::STATUS_PUBLISHED, self::STATUS_YANKED,
    ];

    protected $table = 'releases';

    protected string $publicIdType = 'rel';

    protected $fillable = [
        'extension_uuid', 'created_by_account_uuid', 'version', 'channel', 'status', 'manifest', 'compat',
        'engine_tarball_file_uuid', 'api_tarball_file_uuid', 'engine_dist_file_uuid', 'size_bytes', 'checksum',
        'notes', 'published_at', 'yanked_at', 'meta',
    ];

    protected $hidden = ['id'];

    protected $casts = [
        'manifest' => 'array',
        'compat' => 'array',
        'size_bytes' => 'integer',
        'published_at' => 'datetime',
        'yanked_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function newFactory(): ReleaseFactory
    {
        return ReleaseFactory::new();
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'created_by_account_uuid', 'uuid');
    }

    public function engineTarball(): BelongsTo
    {
        return $this->belongsTo(File::class, 'engine_tarball_file_uuid', 'uuid');
    }

    public function apiTarball(): BelongsTo
    {
        return $this->belongsTo(File::class, 'api_tarball_file_uuid', 'uuid');
    }

    public function engineDist(): BelongsTo
    {
        return $this->belongsTo(File::class, 'engine_dist_file_uuid', 'uuid');
    }

    public function checks(): HasMany
    {
        return $this->hasMany(ReleaseCheck::class, 'release_uuid', 'uuid');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'release_uuid', 'uuid')->orderBy('created_at');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isYanked(): bool
    {
        return $this->status === self::STATUS_YANKED;
    }

    /**
     * All automated checks passed (and at least one exists).
     */
    public function checksPassed(): bool
    {
        $checks = $this->relationLoaded('checks') ? $this->checks : $this->checks()->get();

        return $checks->isNotEmpty() && $checks->every(fn (ReleaseCheck $check) => $check->passed);
    }

    /**
     * Rule from the data model: publishable when every check passes and the latest
     * review action is `approve`. Auto-publish policy (D6) is applied by the caller.
     */
    public function isPublishable(): bool
    {
        if (! $this->checksPassed()) {
            return false;
        }

        $latestReview = $this->reviews()->latest('created_at')->first();

        return $latestReview?->action === Review::ACTION_APPROVE;
    }
}
