<?php

namespace App\Http\Controllers\Admin;

use App\Commerce\FinanceReport;
use App\Commerce\Refunds;
use App\Http\Controllers\Controller;
use App\Http\Resources\FinancePurchaseResource;
use App\Models\Purchase;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fleetbase staff's view of the money: fee revenue, refunds, disputes and per-publisher totals over
 * a date range (from the service's own purchases), every purchase with what is left to refund,
 * a monthly CSV, and refunds made through Stripe.
 */
class FinanceController extends Controller
{
    public const PER_PAGE = 25;

    /**
     * GET /v1/admin/finance[?from=&to=] — totals per currency, per month and per publisher.
     */
    public function summary(Request $request): JsonResponse
    {
        return response()->json($this->report($request)->summary());
    }

    /**
     * GET /v1/admin/finance/purchases[?from=&to=&status=&publisher=&extension=&page=] — newest first.
     */
    public function purchases(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', 'string', Rule::in([Purchase::STATUS_PAID, Purchase::STATUS_REFUNDED, Purchase::STATUS_DISPUTED])],
            'publisher' => ['nullable', 'string', 'max:191'],
            'extension' => ['nullable', 'string', 'max:191'],
        ]);

        $page = $this->report($request)->purchases()
            ->when($request->input('status'), fn (Builder $q, $status) => $q->where('status', $status))
            ->when($request->input('publisher'), fn (Builder $q, $publisher) => $q->whereHas('extension', fn (Builder $e) => $e->withTrashed()
                ->whereHas('organization', fn (Builder $o) => $o->withTrashed()->where(fn (Builder $w) => $w->where('slug', $publisher)->orWhere('public_id', $publisher)))))
            ->when($request->input('extension'), fn (Builder $q, $extension) => $q->whereHas('extension', fn (Builder $e) => $e->withTrashed()
                ->where(fn (Builder $w) => $w->where('slug', $extension)->orWhere('public_id', $extension))))
            ->with(['buyer' => fn ($q) => $q->withTrashed(), 'extension' => fn ($q) => $q->withTrashed()->with(['organization' => fn ($o) => $o->withTrashed()])])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(min(100, max(1, (int) $request->input('per_page', self::PER_PAGE))));

        return response()->json([
            'data' => FinancePurchaseResource::collection($page->getCollection())->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /**
     * GET /v1/admin/finance/monthly.csv[?from=&to=&by=publisher] — one row per month and currency
     * (and publisher with `by=publisher`), amounts in major units.
     */
    public function export(Request $request): StreamedResponse
    {
        $request->validate(['by' => ['nullable', 'string', 'in:publisher']]);
        $report = $this->report($request);
        $byPublisher = $request->input('by') === 'publisher';
        $rows = $byPublisher ? $report->monthlyByPublisher() : $report->summary()['months'];
        $name = 'fleetbase-finance-'.$report->from->format('Y-m').'-to-'.$report->to->format('Y-m').($byPublisher ? '-by-publisher' : '').'.csv';

        return response()->streamDownload(function () use ($rows, $byPublisher) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_merge(['month'], $byPublisher ? ['publisher', 'publisher_id'] : [], ['currency'], FinanceReport::FIELDS));
            foreach ($rows as $row) {
                fputcsv($out, array_merge(
                    [$row['month']],
                    $byPublisher ? [$row['organization']['name'] ?? '', $row['organization']['id'] ?? ''] : [],
                    [$row['currency']],
                    array_map(fn (string $field) => in_array($field, ['sales', 'refunds', 'disputes'], true) ? $row[$field] : static::decimal($row[$field]), FinanceReport::FIELDS),
                ));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * POST /v1/admin/purchases/{purchase}/refund — `{amount?, reason?, note?}`. `amount` is in minor
     * units and defaults to what is left. Answers 202 with Stripe's refund: the purchase turns
     * `refunded` (and its licence revoked) when Stripe confirms with `charge.refunded`.
     */
    public function refund(Request $request, Refunds $refunds, string $purchase): JsonResponse
    {
        $request->validate([
            'amount' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', Rule::in(Refunds::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $model = Purchase::findByIdOrFail($purchase, ['buyer', 'extension.organization']);
        $refund = $refunds->refund(
            $model,
            $request->user(),
            $request->filled('amount') ? (int) $request->input('amount') : null,
            $request->input('reason'),
            $request->filled('note') ? trim((string) $request->input('note')) : null,
        );

        return response()->json([
            'refund' => [
                'id' => $refund->id,
                'amount' => (int) $refund->amount,
                'currency' => strtoupper((string) $refund->currency),
                'status' => $refund->status,
                'reason' => $refund->reason,
            ],
            'purchase' => (new FinancePurchaseResource($model))->resolve($request),
        ], 202);
    }

    protected function report(Request $request): FinanceReport
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => array_merge(['nullable', 'date'], $request->filled('from') ? ['after_or_equal:from'] : []),
        ]);

        return FinanceReport::between($request->input('from'), $request->input('to'));
    }

    protected static function decimal(int $minor): string
    {
        return number_format($minor / 100, 2, '.', '');
    }
}
