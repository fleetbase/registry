<?php

namespace App\Http\Resources;

class PriceResource extends FleetbaseResource
{
    public function toArray($request): array
    {
        if ($this->resource === null) {
            return ['model' => 'free', 'amount' => 0, 'currency' => 'USD', 'on_sale' => false, 'sale_amount' => null, 'effective_amount' => 0];
        }

        return [
            'model' => $this->model,
            'amount' => (int) $this->amount,
            'currency' => $this->currency,
            'on_sale' => $this->resource->isOnSale(),
            'sale_amount' => $this->resource->hasSaleAmount() ? (int) $this->sale_amount : null,
            'effective_amount' => $this->effective_amount,
        ];
    }
}
