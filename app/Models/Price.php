<?php

namespace App\Models;

use App\Casts\Money;
use App\Traits\HasUuid;
use Database\Factories\PriceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pricing for an extension. Amounts are minor units (cents) with a currency column.
 */
class Price extends Model
{
    use HasFactory;
    use HasUuid;

    public const MODEL_FREE = 'free';

    public const MODEL_ONE_TIME = 'one_time';

    public const MODELS = [self::MODEL_FREE, self::MODEL_ONE_TIME];

    protected $table = 'prices';

    protected $fillable = [
        'extension_uuid', 'model', 'amount', 'currency', 'sale_amount', 'on_sale',
        'stripe_product_id', 'stripe_price_id', 'stripe_sale_price_id', 'interval', 'interval_count', 'trial_days',
    ];

    protected $hidden = ['id', 'stripe_product_id', 'stripe_price_id', 'stripe_sale_price_id'];

    protected $casts = [
        'amount' => Money::class,
        'sale_amount' => Money::class,
        'on_sale' => 'boolean',
    ];

    protected static function newFactory(): PriceFactory
    {
        return PriceFactory::new();
    }

    public function extension(): BelongsTo
    {
        return $this->belongsTo(Extension::class, 'extension_uuid', 'uuid');
    }

    public function isFree(): bool
    {
        return $this->model === self::MODEL_FREE || (int) $this->amount === 0;
    }

    /**
     * A sale amount is set: positive and below the regular amount. (Money stores a cleared
     * sale amount as 0.)
     */
    public function hasSaleAmount(): bool
    {
        return (int) $this->sale_amount > 0 && (int) $this->sale_amount < (int) $this->amount;
    }

    /**
     * The sale is on: `on_sale` with a sale amount.
     */
    public function isOnSale(): bool
    {
        return (bool) $this->on_sale && $this->hasSaleAmount();
    }

    /**
     * The amount a buyer pays right now, honouring an active sale.
     */
    public function getEffectiveAmountAttribute(): int
    {
        return $this->isOnSale() ? (int) $this->sale_amount : (int) $this->amount;
    }

    /**
     * The Stripe price Checkout charges: the sale price while the sale is on.
     */
    public function checkoutStripePriceId(): ?string
    {
        return $this->isOnSale() ? $this->stripe_sale_price_id : $this->stripe_price_id;
    }
}
