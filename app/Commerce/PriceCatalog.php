<?php

namespace App\Commerce;

use App\Models\Extension;
use App\Models\Price;

/**
 * Mirrors a listing's price to Stripe on the platform account: one product per listing, the
 * regular price, and the sale price as a second price (`on_sale` chooses which one Checkout
 * charges). Stripe prices cannot change amount or currency, so a change creates a new price and
 * archives the old one. Call before saving: it reads which attributes changed and sets the ids.
 */
class PriceCatalog
{
    public function sync(Price $price, Extension $extension): Price
    {
        $stripe = Stripe::client();

        if ($price->isFree()) {
            $this->archive($price->stripe_price_id);
            $this->archive($price->stripe_sale_price_id);
            $price->forceFill(['stripe_price_id' => null, 'stripe_sale_price_id' => null]);

            return $price;
        }

        $product = $this->product($price, $extension);
        $currencyChanged = ! $price->exists || $price->isDirty('currency');

        if (! $price->stripe_price_id || $currencyChanged || $price->isDirty('amount')) {
            $created = $stripe->prices->create($this->priceParams($price, $extension, $product, (int) $price->amount, 'regular'));
            $this->archive($price->stripe_price_id);
            $price->forceFill(['stripe_price_id' => $created->id]);
        }

        if ($price->hasSaleAmount()) {
            if (! $price->stripe_sale_price_id || $currencyChanged || $price->isDirty('sale_amount')) {
                $created = $stripe->prices->create($this->priceParams($price, $extension, $product, (int) $price->sale_amount, 'sale'));
                $this->archive($price->stripe_sale_price_id);
                $price->forceFill(['stripe_sale_price_id' => $created->id]);
            }
        } elseif ($price->stripe_sale_price_id) {
            $this->archive($price->stripe_sale_price_id);
            $price->forceFill(['stripe_sale_price_id' => null]);
        }

        return $price;
    }

    /**
     * The listing's product, created on first sync and renamed to follow the listing.
     */
    protected function product(Price $price, Extension $extension): string
    {
        $stripe = Stripe::client();
        $params = [
            'name' => $extension->name,
            'metadata' => ['extension' => $extension->public_id, 'extension_uuid' => $extension->uuid],
        ];

        if ($price->stripe_product_id) {
            $stripe->products->update($price->stripe_product_id, $params + ['active' => true]);

            return $price->stripe_product_id;
        }

        $product = $stripe->products->create(array_filter($params + [
            'tax_code' => config('registry.commerce.tax.code'),
        ]));
        $price->forceFill(['stripe_product_id' => $product->id]);

        return $product->id;
    }

    protected function priceParams(Price $price, Extension $extension, string $product, int $amount, string $kind): array
    {
        return [
            'product' => $product,
            'unit_amount' => $amount,
            'currency' => strtolower($price->currency),
            'tax_behavior' => config('registry.commerce.tax.behavior', 'exclusive'),
            'metadata' => ['extension' => $extension->public_id, 'kind' => $kind],
        ];
    }

    protected function archive(?string $id): void
    {
        if ($id) {
            Stripe::client()->prices->update($id, ['active' => false]);
        }
    }
}
