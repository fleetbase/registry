<?php

namespace App\Http\Controllers\Organization;

use App\Commerce\ConnectAccounts;
use App\Commerce\Stripe;
use App\Http\Controllers\Controller;
use App\Http\Resources\PayoutTransactionResource;
use App\Models\Organization;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Stripe\StripeObject;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A publisher organization's money: the connected account's balance and payout schedule from
 * Stripe, and its sales (gross, Fleetbase's fee, net, status, customer) from the service's own
 * purchases, as JSON or CSV. Owners, admins and billing members.
 */
class PayoutController extends Controller
{
    use ActsForOrganization;

    public const PER_PAGE = 25;

    /**
     * GET /v1/organizations/{slug}/payouts — Connect status, balance (available, pending, in
     * transit, per currency), payout schedule and recent payouts.
     */
    public function summary(Request $request, string $slug): JsonResponse
    {
        $organization = $this->billingOrganization($request, $slug);
        $connect = ConnectAccounts::summary($organization);

        if (! $organization->hasStripeAccount()) {
            return response()->json(['connect' => $connect, 'balance' => null, 'schedule' => null, 'payouts' => []]);
        }

        $stripe = Stripe::client();
        $on = Stripe::onAccount($organization->stripe_connect_id);
        $balance = $stripe->balance->retrieve(null, $on);
        $payouts = $stripe->payouts->all(['limit' => 100], $on);
        $account = $stripe->accounts->retrieve($organization->stripe_connect_id);
        $schedule = $account['settings']['payouts']['schedule'] ?? null;

        $inTransit = [];
        foreach ($payouts->data as $payout) {
            if ($payout->status === 'in_transit') {
                $currency = strtoupper($payout->currency);
                $inTransit[$currency] = ($inTransit[$currency] ?? 0) + (int) $payout->amount;
            }
        }

        return response()->json([
            'connect' => $connect,
            'balance' => [
                'available' => $this->amounts($balance->available ?? []),
                'pending' => $this->amounts($balance->pending ?? []),
                'in_transit' => collect($inTransit)->map(fn ($amount, $currency) => ['amount' => $amount, 'currency' => $currency])->values()->all(),
            ],
            'schedule' => $schedule ? [
                'interval' => $schedule['interval'] ?? null,
                'delay_days' => $schedule['delay_days'] ?? null,
                'weekly_anchor' => $schedule['weekly_anchor'] ?? null,
                'monthly_anchor' => $schedule['monthly_anchor'] ?? null,
            ] : null,
            'payouts' => collect($payouts->data)->take(10)->map(fn ($payout) => [
                'id' => $payout->id,
                'amount' => (int) $payout->amount,
                'currency' => strtoupper($payout->currency),
                'status' => $payout->status,
                'arrival_date' => $payout->arrival_date ? Carbon::createFromTimestamp($payout->arrival_date)->toIso8601String() : null,
                'created_at' => Carbon::createFromTimestamp($payout->created)->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    /**
     * GET /v1/organizations/{slug}/payouts/transactions[?from=&to=&extension=&status=&page=] —
     * sales of the organization's extensions, newest first, with totals per currency (refunded
     * sales left out of the totals).
     */
    public function transactions(Request $request, string $slug): JsonResponse
    {
        $organization = $this->billingOrganization($request, $slug);
        $query = $this->sales($request, $organization);

        // Refunded sales are listed but not counted: their money went back to the buyer.
        $totals = (clone $query)->reorder()
            ->where('status', '!=', Purchase::STATUS_REFUNDED)
            ->selectRaw('currency, count(*) as count, sum(amount_total) as gross, sum(fee_amount) as fee, sum(net_amount) as net')
            ->groupBy('currency')
            ->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'count' => (int) $row->count, 'gross' => (int) $row->gross, 'fee' => (int) $row->fee, 'net' => (int) $row->net])
            ->values();

        $page = $query->with(['extension', 'buyer'])->paginate(min(100, max(1, (int) $request->input('per_page', self::PER_PAGE))));

        return response()->json([
            'data' => PayoutTransactionResource::collection($page->getCollection())->resolve($request),
            'totals' => $totals,
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * GET /v1/organizations/{slug}/payouts/transactions.csv — the same sales (same filters) as CSV.
     */
    public function export(Request $request, string $slug): StreamedResponse
    {
        $organization = $this->billingOrganization($request, $slug);
        $query = $this->sales($request, $organization)->with(['extension', 'buyer']);
        $name = "{$organization->slug}-transactions-".now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'id', 'extension', 'customer', 'status', 'currency', 'gross', 'fee', 'net', 'tax', 'refunded']);
            $query->chunk(500, function ($purchases) use ($out) {
                foreach ($purchases as $purchase) {
                    fputcsv($out, [
                        $purchase->created_at?->toIso8601String(),
                        $purchase->public_id,
                        $purchase->extension?->name,
                        $purchase->buyer?->name,
                        $purchase->status,
                        $purchase->currency,
                        static::decimal($purchase->amount_total),
                        static::decimal($purchase->fee_amount),
                        static::decimal($purchase->net_amount),
                        static::decimal($purchase->meta['amount_tax'] ?? 0),
                        static::decimal($purchase->meta['amount_refunded'] ?? 0),
                    ]);
                }
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    protected function billingOrganization(Request $request, string $slug): Organization
    {
        $organization = $this->findOrganization($request, $slug);
        $this->assertRole($organization, self::BILLING_ROLES, 'Only organization owners, admins and billing members can see payouts.');

        return $organization;
    }

    protected function sales(Request $request, Organization $organization): Builder
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'status' => ['nullable', 'string', 'in:'.implode(',', [Purchase::STATUS_PAID, Purchase::STATUS_REFUNDED, Purchase::STATUS_DISPUTED])],
            'extension' => ['nullable', 'string', 'max:191'],
        ]);

        return Purchase::query()
            ->whereHas('extension', fn (Builder $q) => $q->withTrashed()->where('organization_uuid', $organization->uuid))
            ->when($request->input('from'), fn (Builder $q, $from) => $q->where('created_at', '>=', Carbon::parse($from)->startOfDay()))
            ->when($request->input('to'), fn (Builder $q, $to) => $q->where('created_at', '<=', Carbon::parse($to)->endOfDay()))
            ->when($request->input('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->input('extension'), fn (Builder $q, $extension) => $q->whereHas('extension', fn (Builder $e) => $e->where('slug', $extension)->orWhere('public_id', $extension)))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * @param  iterable<StripeObject>  $amounts
     */
    protected function amounts(iterable $amounts): array
    {
        $out = [];
        foreach ($amounts as $amount) {
            $out[] = ['amount' => (int) $amount->amount, 'currency' => strtoupper($amount->currency)];
        }

        return $out;
    }

    protected static function decimal(int|string|null $minor): string
    {
        return number_format(((int) $minor) / 100, 2, '.', '');
    }
}
