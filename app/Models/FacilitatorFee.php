<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fleetbase's fee on a sale, in basis points (1000 = 10%), with history: the row with the latest
 * `effective_from` that has passed is in effect. Rows are only ever added, so a fee change never
 * rewrites what past purchases paid (each checkout also keeps the rate it used).
 */
class FacilitatorFee extends Model
{
    use HasUuid;

    protected $table = 'facilitator_fees';

    protected $fillable = ['rate_bps', 'effective_from', 'note', 'created_by_account_uuid'];

    protected $hidden = ['id'];

    protected $casts = ['rate_bps' => 'integer', 'effective_from' => 'datetime'];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'created_by_account_uuid', 'uuid');
    }

    /**
     * The rate in effect at a moment, falling back to `registry.commerce.facilitator_fee_bps`
     * before the first row takes effect.
     */
    public static function rateAt(?\DateTimeInterface $at = null): int
    {
        $rate = static::query()
            ->where('effective_from', '<=', $at ?? now())
            ->orderByDesc('effective_from')
            ->orderByDesc('id')
            ->value('rate_bps');

        return $rate ?? (int) config('registry.commerce.facilitator_fee_bps', 1000);
    }

    /**
     * The fee on an amount in minor units, rounded half up.
     */
    public static function feeFor(int $amount, int $rateBps): int
    {
        return (int) round($amount * $rateBps / 10000, 0, PHP_ROUND_HALF_UP);
    }
}
