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
 *
 * A `grant` licence is access the owner (or staff) gave an organization at no charge: how a
 * private extension reaches its clients. `instance_uuids` limits it to some of the
 * organization's instances; null means all of them (and the organization's personal tokens).
 */
class Licence extends Model
{
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const KIND_FREE = 'free';

    public const KIND_PAID = 'paid';

    /** Access given by the extension's owner or staff, at no charge (private extensions, comps). */
    public const KIND_GRANT = 'grant';

    public const KINDS = [self::KIND_FREE, self::KIND_PAID, self::KIND_GRANT];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'licences';

    protected string $publicIdType = 'lic';

    protected $fillable = ['extension_uuid', 'organization_uuid', 'purchase_uuid', 'kind', 'max_activations', 'instance_uuids', 'status', 'expires_at', 'meta'];

    protected $hidden = ['id'];

    protected $casts = ['max_activations' => 'integer', 'instance_uuids' => 'array', 'expires_at' => 'datetime', 'meta' => 'array'];

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

    public function isGrant(): bool
    {
        return $this->kind === self::KIND_GRANT;
    }

    /**
     * Whether the licence is limited to some instances (`instance_uuids`).
     */
    public function isInstanceLimited(): bool
    {
        return is_array($this->instance_uuids) && $this->instance_uuids !== [];
    }

    /**
     * Whether this licence covers the caller: any caller of its organization, unless it is
     * limited to instances; then only those instances (personal tokens are refused).
     */
    public function covers(?Instance $instance): bool
    {
        if (! $this->isInstanceLimited()) {
            return true;
        }

        return $instance !== null && in_array($instance->uuid, $this->instance_uuids, true);
    }

    public function hasFreeSeat(): bool
    {
        return $this->isUnlimited() || $this->seatsUsed() < $this->max_activations;
    }
}
