<?php

namespace App\Models;

use App\Http\Filter\ExtensionFilter;
use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Database\Factories\ExtensionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A catalog listing. Listing fields are independent of releases; only releases go through review.
 */
class Extension extends Model
{
    use HasFactory;
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const HOSTING_CLOUD = 'cloud';

    public const HOSTING_SELF_HOSTED = 'self_hosted';

    public const HOSTING_BOTH = 'both';

    public const HOSTINGS = [self::HOSTING_CLOUD, self::HOSTING_SELF_HOSTED, self::HOSTING_BOTH];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_IN_REVIEW = 'in_review';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_UNPUBLISHED = 'unpublished';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_IN_REVIEW, self::STATUS_APPROVED, self::STATUS_REJECTED,
        self::STATUS_PUBLISHED, self::STATUS_UNPUBLISHED, self::STATUS_SUSPENDED,
    ];

    protected $table = 'extensions';

    protected string $publicIdType = 'ext';

    protected string $filter = ExtensionFilter::class;

    protected $fillable = [
        'organization_uuid', 'category_uuid', 'icon_file_uuid', 'created_by_account_uuid', 'slug', 'name',
        'subtitle', 'description', 'promotional_text', 'tags', 'languages', 'hosting', 'status',
        'website_url', 'repo_url', 'support_url', 'privacy_url', 'tos_url', 'copyright', 'fa_icon',
        'published_at', 'meta',
    ];

    protected $hidden = ['id'];

    protected $casts = [
        'tags' => 'array',
        'languages' => 'array',
        'published_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function newFactory(): ExtensionFactory
    {
        return ExtensionFactory::new();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function publisher(): BelongsTo
    {
        return $this->organization();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_uuid', 'uuid');
    }

    public function icon(): BelongsTo
    {
        return $this->belongsTo(File::class, 'icon_file_uuid', 'uuid');
    }

    public function screenshots(): MorphMany
    {
        return $this->morphMany(File::class, 'subject', 'subject_type', 'subject_uuid', 'uuid')
            ->where('type', File::TYPE_SCREENSHOT)
            ->orderBy('created_at');
    }

    public function packages(): HasMany
    {
        return $this->hasMany(ExtensionPackage::class, 'extension_uuid', 'uuid');
    }

    public function price(): HasOne
    {
        return $this->hasOne(Price::class, 'extension_uuid', 'uuid');
    }

    public function releases(): HasMany
    {
        return $this->hasMany(Release::class, 'extension_uuid', 'uuid');
    }

    public function publishedReleases(): HasMany
    {
        return $this->releases()->where('status', Release::STATUS_PUBLISHED)->orderByDesc('published_at');
    }

    /**
     * Newest published stable release (falls back to any channel).
     */
    public function latestRelease(): HasOne
    {
        return $this->hasOne(Release::class, 'extension_uuid', 'uuid')
            ->where('status', Release::STATUS_PUBLISHED)
            ->ofMany(['published_at' => 'max', 'id' => 'max'], fn (Builder $q) => $q->where('status', Release::STATUS_PUBLISHED));
    }

    public function installs(): HasMany
    {
        return $this->hasMany(Install::class, 'extension_uuid', 'uuid');
    }

    public function licences(): HasMany
    {
        return $this->hasMany(Licence::class, 'extension_uuid', 'uuid');
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class, 'extension_uuid', 'uuid');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function isPaid(): bool
    {
        return $this->price !== null && $this->price->model !== Price::MODEL_FREE && (int) $this->price->amount > 0;
    }
}
