<?php

namespace App\Http\Resources;

use App\Commerce\FinanceReport;
use App\Commerce\Refunds;
use App\Support\Http;

/**
 * A purchase as Fleetbase staff see it: the publisher and customer, gross, Fleetbase's fee, the
 * publisher's net, refunds, an open dispute, what is left to refund, and the Stripe ids to find it
 * in the Dashboard.
 */
class FinancePurchaseResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        $figures = FinanceReport::figures($this->resource);
        $organization = $this->extension?->organization;

        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'extension' => $this->extension ? (new ExtensionReferenceResource($this->extension))->resolve($request) : null,
            'publisher' => $organization ? ['id' => $organization->public_id, 'slug' => $organization->slug, 'name' => $organization->name] : null,
            'customer' => $this->buyer ? ['id' => $this->buyer->public_id, 'slug' => $this->buyer->slug, 'name' => $this->buyer->name] : null,
            'gross' => $figures['gross'],
            'fee' => $figures['fee'],
            'net' => (int) $this->net_amount,
            'tax' => (int) ($this->meta['amount_tax'] ?? 0),
            'refunded' => $figures['refunded'],
            'fee_refunded' => $figures['fee_refunded'],
            'refundable' => Refunds::refundable($this->resource),
            'currency' => $this->currency,
            'status' => $this->status,
            'dispute' => is_array($this->meta['dispute'] ?? null) ? $this->meta['dispute'] : null,
            'refunded_at' => $this->meta['refunded_at'] ?? null,
            'stripe' => [
                'payment_intent' => $this->stripe_payment_intent_id,
                'charge' => $this->meta['stripe_charge_id'] ?? null,
                'account' => $this->meta['stripe_account'] ?? null,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
