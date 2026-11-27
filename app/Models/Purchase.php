<?php

namespace App\Models;

use App\Casts\Money;
use App\Traits\HasPublicId;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Written only by Stripe webhook handlers and admin actions.
 */
class Purchase extends Model
{
    use HasPublicId;
    use HasUuid;
    use SoftDeletes;

    public const STATUS_PAID = 'paid';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_DISPUTED = 'disputed';

    protected $table = 'purchases';

    protected string $publicIdType = 'pur';

    protected $fillable = [
        'extension_uuid', 'buyer_organization_uuid', 'buyer_account_uuid', 'price_snapshot', 'amount_total',
        'fee_amount', 'net_amount', 'currency', 'stripe_checkout_session_id', 'stripe_payment_intent_id',
        'status', 'receipt_url', 'meta',
    ];

    protected $hidden = ['id', 'stripe_checkout_session_id', 'stripe_payment_intent_id'];

    protected $casts = [
        'price_snapshot' => 'array',
        'amount_total' => Money::class,
        'fee_amount' => Money::class,
        'net_amount' => Money::class,
        'meta' => 'array',
    ];

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'buyer_organization_uuid', 'uuid');
    }

    public function buyerAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'buyer_account_uuid', 'uuid');
    }

    public function licence(): HasOne
    {
        return $this->hasOne(Licence::class, 'purchase_uuid', 'uuid');
    }
}
