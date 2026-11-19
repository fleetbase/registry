<?php

namespace App\Models;

use App\Http\Filter\ExtensionFilter;
use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Database\Factories\ExtensionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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

    public const VISIBILITY_PUBLIC = 'public';

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITIES = [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE];

    protected $table = 'extensions';

    protected string $publicIdType = 'ext';

    protected string $filter = ExtensionFilter::class;

    protected $fillable = [
        'organization_uuid', 'category_uuid', 'icon_file_uuid', 'created_by_account_uuid', 'slug', 'name',
        'subtitle', 'description', 'promotional_text', 'tags', 'languages', 'hosting', 'status',
        'visibility', 'website_url', 'repo_url', 'support_url', 'privacy_url', 'tos_url', 'copyright', 'fa_icon',
        'published_at', 'meta',
    ];

    protected $hidden = ['id'];

    protected $attributes = ['visibility' => self::VISIBILITY_PUBLIC];

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

    /** Most screenshots a listing can have. */
    public const MAX_SCREENSHOTS = 8;

    /**
     * The loaded screenshots in display order: `meta.order` (set by the publisher's reorder),
     * then upload time. Imported screenshots have no order and keep their upload order.
     *
     * @return Collection<int, File>
     */
    public function orderedScreenshots(): Collection
    {
        return $this->screenshots->sortBy([
            fn (File $a, File $b) => ($a->meta['order'] ?? PHP_INT_MAX) <=> ($b->meta['order'] ?? PHP_INT_MAX),
            fn (File $a, File $b) => [$a->created_at, $a->id] <=> [$b->created_at, $b->id],
        ])->values();
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

    public function grants(): HasMany
    {
        return $this->licences()->where('kind', Licence::KIND_GRANT);
    }

    public function trustedPublishers(): HasMany
    {
        return $this->hasMany(TrustedPublisher::class, 'extension_uuid', 'uuid');
    }

    /**
     * Listings anyone may see: the catalog, search, publisher pages, category counts. Private
     * listings join through `App\Services\ExtensionAccess::visible()` for callers with access.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('visibility'), self::VISIBILITY_PUBLIC);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === self::VISIBILITY_PRIVATE;
    }

    /**
     * `public` while a private listing waits for a human-reviewed release to go public
     * (`meta.pending_visibility`); null otherwise.
     */
    public function pendingVisibility(): ?string
    {
        $pending = $this->meta['pending_visibility'] ?? null;

        return $this->isPrivate() && $pending === self::VISIBILITY_PUBLIC ? $pending : null;
    }

    /**
     * The visibility the next release is reviewed for: the pending one, else the current one.
     */
    public function targetVisibility(): string
    {
        return $this->pendingVisibility() ?? $this->visibility ?? self::VISIBILITY_PUBLIC;
    }

    /**
     * Whether package metadata and archives may sit in shared caches: free and public only.
     */
    public function isPubliclyCacheable(): bool
    {
        return ! $this->isPrivate() && ! $this->isPaid();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    /**
     * An extension by slug, public id, uuid, or one of its npm or Composer package names (what
     * an instance knows from its installed packages).
     */
    public static function findByIdentifier(string $identifier): ?static
    {
        $identifier = urldecode($identifier);

        $extension = static::query()
            ->where(fn ($q) => $q->where('slug', $identifier)->orWhere('public_id', $identifier))
            ->when(Str::isUuid($identifier), fn ($q) => $q->orWhere('uuid', $identifier))
            ->first();

        if ($extension) {
            return $extension;
        }

        $package = ExtensionPackage::query()->where('name', $identifier)->first();

        return $package ? static::find($package->extension_uuid) : null;
    }

    public function isPaid(): bool
    {
        return $this->price !== null && $this->price->model !== Price::MODEL_FREE && (int) $this->price->amount > 0;
    }
}
