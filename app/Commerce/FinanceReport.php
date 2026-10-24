<?php

namespace App\Commerce;

use App\Models\Purchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Fleetbase's side of the money over a date range, read from `purchases`: fee revenue, refunds,
 * disputes and what went to publishers, per currency, per month and per publisher organization.
 *
 * Purchases count in the range their sale falls in (`created_at`), refunds and disputes with them,
 * so a refund made in November of an October sale changes October. Amounts are minor units.
 */
class FinanceReport
{
    /** The figures every bucket (total, month, publisher) carries. */
    public const FIELDS = ['sales', 'gross', 'refunds', 'refunded', 'fee', 'fee_refunded', 'fee_revenue', 'publisher_net', 'disputes', 'disputed'];

    public function __construct(public readonly Carbon $from, public readonly Carbon $to) {}

    /**
     * The range from `from` and `to` (dates, inclusive). Without them, the last 12 months
     * including this one.
     */
    public static function between(?string $from, ?string $to): static
    {
        $end = $to ? Carbon::parse($to)->endOfDay() : now()->endOfDay();
        $start = $from ? Carbon::parse($from)->startOfDay() : $end->copy()->subMonthsNoOverflow(11)->startOfMonth();

        return new static($start, $end);
    }

    public function purchases(): Builder
    {
        return Purchase::query()->whereBetween('created_at', [$this->from, $this->to]);
    }

    /**
     * Totals per currency, per month and currency, and per publisher and currency (highest fee
     * revenue first).
     *
     * @return array{from: string, to: string, totals: list<array>, months: list<array>, publishers: list<array>}
     */
    public function summary(): array
    {
        $buckets = $this->aggregate();

        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'totals' => $buckets['totals'],
            'months' => $buckets['months'],
            'publishers' => $buckets['publishers'],
        ];
    }

    /**
     * Rows per month, publisher and currency: oldest month first, highest fee revenue first within it.
     *
     * @return list<array>
     */
    public function monthlyByPublisher(): array
    {
        return $this->aggregate()['month_publishers'];
    }

    /**
     * @return array{totals: list<array>, months: list<array>, publishers: list<array>, month_publishers: list<array>}
     */
    protected function aggregate(): array
    {
        $totals = $months = $publishers = $monthPublishers = [];

        $this->purchases()
            ->with(['extension' => fn ($q) => $q->withTrashed()->with(['organization' => fn ($o) => $o->withTrashed()])])
            ->lazyById(500)
            ->each(function (Purchase $purchase) use (&$totals, &$months, &$publishers, &$monthPublishers) {
                $figures = static::figures($purchase);
                $currency = $purchase->currency;
                $month = $purchase->created_at->format('Y-m');
                $organization = $purchase->extension?->organization;
                $publisher = [
                    'organization' => $organization ? ['id' => $organization->public_id, 'slug' => $organization->slug, 'name' => $organization->name] : null,
                    'currency' => $currency,
                ];
                $key = ($organization?->uuid ?? '').'|'.$currency;

                $totals[$currency] = static::add($totals[$currency] ?? ['currency' => $currency] + static::zero(), $figures);
                $months["{$month}|{$currency}"] = static::add($months["{$month}|{$currency}"] ?? ['month' => $month, 'currency' => $currency] + static::zero(), $figures);
                $publishers[$key] = static::add($publishers[$key] ?? $publisher + static::zero(), $figures);
                $monthPublishers[$month][$key] = static::add($monthPublishers[$month][$key] ?? ['month' => $month] + $publisher + static::zero(), $figures);
            });

        ksort($totals);
        ksort($months);
        ksort($monthPublishers);

        return [
            'totals' => array_values($totals),
            'months' => array_values($months),
            'publishers' => static::ranked($publishers),
            'month_publishers' => array_merge([], ...array_map(fn (array $rows) => static::ranked($rows), array_values($monthPublishers))),
        ];
    }

    /**
     * One purchase's figures. A refund returns the application fee in proportion to the amount
     * refunded (as `refund_application_fee` does) and takes the rest back from the publisher
     * (`reverse_transfer`). A dispute is counted, not subtracted: its outcome is decided in Stripe.
     *
     * @return array<string, int>
     */
    public static function figures(Purchase $purchase): array
    {
        $gross = (int) $purchase->amount_total;
        $fee = (int) $purchase->fee_amount;
        $net = (int) $purchase->net_amount;
        $refunded = min($gross, (int) ($purchase->meta['amount_refunded'] ?? 0));
        if ($purchase->status === Purchase::STATUS_REFUNDED && $refunded === 0) {
            $refunded = $gross;
        }
        $feeRefunded = $gross > 0 ? min($fee, (int) round($fee * $refunded / $gross)) : 0;
        $dispute = $purchase->meta['dispute'] ?? null;

        return [
            'sales' => 1,
            'gross' => $gross,
            'refunds' => $refunded > 0 ? 1 : 0,
            'refunded' => $refunded,
            'fee' => $fee,
            'fee_refunded' => $feeRefunded,
            'fee_revenue' => $fee - $feeRefunded,
            'publisher_net' => $net - ($refunded - $feeRefunded),
            'disputes' => is_array($dispute) ? 1 : 0,
            'disputed' => is_array($dispute) ? (int) ($dispute['amount'] ?? $gross) : 0,
        ];
    }

    /**
     * @return array<string, int>
     */
    protected static function zero(): array
    {
        return array_fill_keys(self::FIELDS, 0);
    }

    /**
     * Highest fee revenue first, then highest gross.
     */
    protected static function ranked(array $rows): array
    {
        usort($rows, fn (array $a, array $b) => [$b['fee_revenue'], $b['gross']] <=> [$a['fee_revenue'], $a['gross']]);

        return $rows;
    }

    protected static function add(array $bucket, array $figures): array
    {
        foreach (self::FIELDS as $field) {
            $bucket[$field] += $figures[$field];
        }

        return $bucket;
    }
}
