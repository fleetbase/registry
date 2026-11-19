<?php

namespace App\Http\Resources;

use App\Support\Http;

/**
 * A sale as its publisher sees it: gross, Fleetbase's fee, net to the connected account, status
 * and the customer organization.
 */
class PayoutTransactionResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'extension' => $this->whenLoaded('extension', fn () => $this->extension ? (new ExtensionReferenceResource($this->extension))->resolve($request) : null),
            'customer' => $this->whenLoaded('buyer', fn () => $this->buyer ? ['id' => $this->buyer->public_id, 'slug' => $this->buyer->slug, 'name' => $this->buyer->name] : null),
            'gross' => (int) $this->amount_total,
            'fee' => (int) $this->fee_amount,
            'net' => (int) $this->net_amount,
            'tax' => (int) ($this->meta['amount_tax'] ?? 0),
            'refunded' => (int) ($this->meta['amount_refunded'] ?? 0),
            'fee_rate_bps' => isset($this->meta['fee_rate_bps']) ? (int) $this->meta['fee_rate_bps'] : null,
            'currency' => $this->currency,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}
