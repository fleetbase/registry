<?php

namespace App\Models;

use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A publisher or a buyer. A person can belong to several through memberships.
 */
class Organization extends Model
{
    use HasFactory;
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    protected $table = 'organizations';

    protected string $publicIdType = 'org';

    /** `payout_status` values, set from Stripe's `account.updated`. */
    public const PAYOUT_PENDING = 'pending';

    public const PAYOUT_RESTRICTED = 'restricted';

    public const PAYOUT_ENABLED = 'enabled';

    public const PAYOUT_REJECTED = 'rejected';

    protected $fillable = [
        'slug', 'name', 'website', 'bio', 'avatar_url', 'is_personal', 'verified_at',
        'stripe_connect_id', 'payout_status', 'meta',
    ];

    protected $hidden = ['id', 'stripe_connect_id'];

    // `is_staff` is deliberately not fillable; set it with `registry:staff`.
    protected $casts = [
        'is_personal' => 'boolean',
        'is_staff' => 'boolean',
        'verified_at' => 'datetime',
        'meta' => 'array',
    ];

    protected static function newFactory(): OrganizationFactory
    {
        return OrganizationFactory::new();
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class, 'organization_uuid', 'uuid');
    }

    public function accounts(): BelongsToMany
    {
        return $this->belongsToMany(Account::class, 'memberships', 'organization_uuid', 'account_uuid', 'uuid', 'uuid')
            ->using(Membership::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(Extension::class, 'organization_uuid', 'uuid');
    }

    public function instances(): HasMany
    {
        return $this->hasMany(Instance::class, 'organization_uuid', 'uuid');
    }

    public function licences(): HasMany
    {
        return $this->hasMany(Licence::class, 'organization_uuid', 'uuid');
    }

    public function isStaff(): bool
    {
        return $this->is_staff === true;
    }

    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    public function hasStripeAccount(): bool
    {
        return filled($this->stripe_connect_id);
    }

    /**
     * What `account.updated` last said about the connected account (charges, payouts,
     * transfers capability, requirements), or an empty array.
     */
    public function stripeState(): array
    {
        return (array) ($this->meta['stripe'] ?? []);
    }

    /**
     * Destination charges can be paid out to this organization: its connected account's
     * `transfers` capability is active.
     */
    public function canReceivePayments(): bool
    {
        return $this->hasStripeAccount()
            && ($this->stripeState()['transfers'] ?? null) === 'active'
            && $this->payout_status !== self::PAYOUT_REJECTED;
    }
}
