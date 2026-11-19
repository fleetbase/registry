<?php

namespace App\Models;

use App\Casts\Money;
use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Stripe Checkout Session the service started for a buyer organization, from an instance
 * (the console) or the website. The public id is the hand-off id: it rides the success URL
 * and the buyer polls it until the webhook completes the checkout with a purchase.
 */
class Checkout extends Model
{
    use HasPublicId;
    use HasUuid;

    public const SOURCE_INSTANCE = 'instance';

    public const SOURCE_WEBSITE = 'website';

    public const STATUS_OPEN = 'open';

    public const STATUS_COMPLETE = 'complete';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'checkouts';

    protected string $publicIdType = 'chk';

    protected $fillable = [
        'extension_uuid', 'organization_uuid', 'account_uuid', 'instance_uuid', 'source', 'status', 'amount',
        'currency', 'fee_amount', 'fee_rate_bps', 'price_snapshot', 'stripe_checkout_session_id', 'checkout_url',
        'purchase_uuid', 'expires_at', 'completed_at',
    ];

    protected $hidden = ['id', 'stripe_checkout_session_id'];

    protected $casts = [
        'amount' => Money::class,
        'fee_amount' => Money::class,
        'fee_rate_bps' => 'integer',
        'price_snapshot' => 'array',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_uuid', 'uuid');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_uuid', 'uuid');
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(Instance::class, 'instance_uuid', 'uuid');
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class, 'purchase_uuid', 'uuid');
    }

    /**
     * Open, but past the session's expiry (the `checkout.session.expired` webhook may lag).
     */
    public function hasLapsed(): bool
    {
        return $this->status === self::STATUS_OPEN && $this->expires_at !== null && $this->expires_at->isPast();
    }
}
