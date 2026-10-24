<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A licence belongs to an organization and is activated per instance (seat = instance).
 * Free extensions get one automatically on first install so every path checks one thing.
 */
class Licence extends Model
{
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const KIND_FREE = 'free';

    public const KIND_PAID = 'paid';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'licences';

    protected string $publicIdType = 'lic';

    protected $fillable = ['extension_uuid', 'organization_uuid', 'purchase_uuid', 'kind', 'max_activations', 'status', 'expires_at', 'meta'];

    protected $hidden = ['id'];

    protected $casts = ['max_activations' => 'integer', 'expires_at' => 'datetime', 'meta' => 'array'];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_uuid', 'uuid');
    }

    public function activations(): HasMany
    {
        return $this->hasMany(LicenceActivation::class, 'licence_uuid', 'uuid');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Null `max_activations` means unlimited seats (automatic free licences).
     */
    public function isUnlimited(): bool
    {
        return $this->max_activations === null;
    }

    public function activeActivations(): HasMany
    {
        return $this->activations()->whereNull('deactivated_at');
    }

    public function seatsUsed(): int
    {
        return $this->activeActivations()->count();
    }

    public function hasFreeSeat(): bool
    {
        return $this->isUnlimited() || $this->seatsUsed() < $this->max_activations;
    }
}
