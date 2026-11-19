<?php

use App\Models\Extension;
use App\Models\Organization;
use App\Models\Price;

it('reads imported accounts and mirrors imported paid prices', function () {
    $organization = Organization::factory()->create(['stripe_connect_id' => 'acct_1QfLmTPublisher01']);
    $listing = Extension::factory()->published()->paid(2500)->for($organization)->create();
    $listing->price->forceFill(['stripe_product_id' => 'prod_imported'])->save();
    Extension::factory()->published()->free()->create();

    $this->artisan('registry:stripe-sync')->assertSuccessful();

    $price = $listing->price->fresh();
    expect($organization->refresh()->payout_status)->toBe(Organization::PAYOUT_ENABLED)
        ->and($price->stripe_product_id)->toBe('prod_imported')
        ->and($price->stripe_price_id)->toStartWith('price_test')
        ->and(stripe()->sent('post', '/v1/prices'))->toHaveCount(1)
        ->and(Price::whereNotNull('stripe_price_id')->count())->toBe(1);

    // Nothing left to mirror on a second run.
    $this->artisan('registry:stripe-sync')->assertSuccessful();
    expect(stripe()->sent('post', '/v1/prices'))->toHaveCount(1);
});

it('needs Stripe configured', function () {
    config(['services.stripe.secret' => null]);

    $this->artisan('registry:stripe-sync')->expectsOutput('Set STRIPE_SECRET first.')->assertFailed();
});
