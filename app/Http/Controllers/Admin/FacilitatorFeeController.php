<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\FacilitatorFee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Fleetbase's facilitator fee with history. A change is a new row with an effective-from time
 * (now or later, never earlier), so past purchases keep the fee they paid.
 */
class FacilitatorFeeController extends Controller
{
    /**
     * GET /v1/admin/facilitator-fees — the rate in effect now and every row, newest first.
     */
    public function index(Request $request): JsonResponse
    {
        $fees = FacilitatorFee::query()->with('createdBy')->orderByDesc('effective_from')->orderByDesc('id')->get();

        return response()->json([
            'current_rate_bps' => FacilitatorFee::rateAt(now()),
            'default_rate_bps' => (int) config('registry.commerce.facilitator_fee_bps', 1000),
            'data' => $fees->map(fn (FacilitatorFee $fee) => $this->present($fee))->values(),
        ]);
    }

    /**
     * POST /v1/admin/facilitator-fees — `{rate_bps, effective_from?, note?}`; 1000 = 10%.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'rate_bps' => ['required', 'integer', 'min:0', 'max:5000'],
            'effective_from' => ['nullable', 'date', 'after_or_equal:'.now()->subMinute()->toIso8601String()],
            'note' => ['nullable', 'string', 'max:255'],
        ], ['effective_from.after_or_equal' => 'A fee change cannot take effect in the past.']);

        $fee = FacilitatorFee::create([
            'rate_bps' => (int) $request->input('rate_bps'),
            'effective_from' => $request->input('effective_from') ?? now(),
            'note' => $request->input('note'),
            'created_by_account_uuid' => $request->user()->uuid,
        ]);

        Event::record('facilitator_fee.scheduled', $fee, $request->user(), ['rate_bps' => $fee->rate_bps, 'effective_from' => $fee->effective_from->toIso8601String()], $request->ip());

        return response()->json($this->present($fee->load('createdBy')), 201);
    }

    protected function present(FacilitatorFee $fee): array
    {
        return [
            'id' => $fee->uuid,
            'rate_bps' => $fee->rate_bps,
            'effective_from' => $fee->effective_from,
            'note' => $fee->note,
            'created_by' => $fee->createdBy ? ['id' => $fee->createdBy->public_id, 'name' => $fee->createdBy->name] : null,
            'created_at' => $fee->created_at,
        ];
    }
}
