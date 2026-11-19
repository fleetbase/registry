<?php

namespace Database\Factories;

use App\Models\Extension;
use App\Models\Price;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Price>
 */
class PriceFactory extends Factory
{
    protected $model = Price::class;

    public function definition(): array
    {
        return [
            'extension_uuid' => Extension::factory(),
            'model' => Price::MODEL_FREE,
            'amount' => 0,
            'currency' => 'USD',
            'on_sale' => false,
        ];
    }

    public function free(): static
    {
        return $this->state(fn () => ['model' => Price::MODEL_FREE, 'amount' => 0]);
    }

    public function oneTime(int $amount = 4900, string $currency = 'USD'): static
    {
        return $this->state(fn () => ['model' => Price::MODEL_ONE_TIME, 'amount' => $amount, 'currency' => $currency]);
    }

    public function onSale(int $saleAmount): static
    {
        return $this->state(fn () => ['on_sale' => true, 'sale_amount' => $saleAmount]);
    }
}
