<?php

namespace App\Http\Resources;

use App\Support\Http;

/**
 * A purchase as its buyer sees it: what was bought, by whom, what it cost, the Stripe receipt and
 * the licence it created.
 */
class PurchaseResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->when(Http::isInternalRequest(), $this->id, $this->public_id),
            'extension' => $this->whenLoaded('extension', fn () => $this->extension ? (new ExtensionReferenceResource($this->extension))->resolve($request) : null),
            'organization' => $this->whenLoaded('buyer', fn () => $this->buyer ? ['id' => $this->buyer->public_id, 'slug' => $this->buyer->slug, 'name' => $this->buyer->name] : null),
            'bought_by' => $this->whenLoaded('buyerAccount', fn () => $this->buyerAccount ? ['id' => $this->buyerAccount->public_id, 'name' => $this->buyerAccount->name, 'email' => $this->buyerAccount->email] : null),
            'source' => $this->meta['source'] ?? null,
            'amount_total' => (int) $this->amount_total,
            'amount_subtotal' => (int) ($this->meta['amount_subtotal'] ?? $this->amount_total),
            'amount_tax' => (int) ($this->meta['amount_tax'] ?? 0),
            'amount_refunded' => (int) ($this->meta['amount_refunded'] ?? 0),
            'currency' => $this->currency,
            'status' => $this->status,
            'receipt_url' => $this->receipt_url,
            'licence' => $this->whenLoaded('licence', fn () => $this->licence ? (new LicenceResource($this->licence))->resolve($request) : null),
            'created_at' => $this->created_at,
        ];
    }
}
