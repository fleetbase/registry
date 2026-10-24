<?php

namespace App\Http\Resources;

use App\Models\Checkout;

/**
 * A hand-off: `open` while the buyer is on Stripe's page (with its `url`), `complete` with the
 * purchase and licence once the webhook landed, or `expired`.
 */
class CheckoutResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        $status = $this->resource->hasLapsed() ? Checkout::STATUS_EXPIRED : $this->status;

        return [
            'handoff' => $this->public_id,
            'status' => $status,
            'source' => $this->source,
            'extension' => $this->whenLoaded('extension', fn () => $this->extension ? (new ExtensionReferenceResource($this->extension))->resolve($request) : null),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization ? ['id' => $this->organization->public_id, 'slug' => $this->organization->slug, 'name' => $this->organization->name] : null),
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            'url' => $status === Checkout::STATUS_OPEN ? $this->checkout_url : null,
            'expires_at' => $this->expires_at,
            'completed_at' => $this->completed_at,
            'purchase' => $this->whenLoaded('purchase', fn () => $this->purchase ? (new PurchaseResource($this->purchase))->resolve($request) : null),
            'created_at' => $this->created_at,
        ];
    }
}
