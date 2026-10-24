<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Token;

function connectedPublisher(): array
{
    [$account, $organization, $listing] = publisher(Membership::ROLE_PUBLISHER);
    $organization->forceFill(['stripe_connect_id' => 'acct_1QfLmTPublisher01', 'payout_status' => Organization::PAYOUT_PENDING])->save();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    return [$account, $organization, $listing, '/v1/publisher/extensions/'.$listing->slug.'/price'];
}

it('mirrors a new paid price to a Stripe product and price', function () {
    [, , $listing, $path] = connectedPublisher();

    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'USD'])->assertOk();

    $product = stripe()->sent('post', '/v1/products')[0]['params'];
    $created = stripe()->sent('post', '/v1/prices')[0]['params'];
    expect($product['name'])->toBe($listing->name)
        ->and($product['metadata'])->toBe(['extension' => $listing->public_id, 'extension_uuid' => $listing->uuid])
        ->and($created)->toMatchArray(['unit_amount' => 4900, 'currency' => 'usd', 'tax_behavior' => 'exclusive'])
        ->and($created['metadata'])->toBe(['extension' => $listing->public_id, 'kind' => 'regular']);

    $price = Price::where('extension_uuid', $listing->uuid)->sole();
    expect($price->stripe_product_id)->toStartWith('prod_')
        ->and($price->stripe_price_id)->toStartWith('price_')
        ->and($price->stripe_sale_price_id)->toBeNull()
        ->and($created['product'])->toBe($price->stripe_product_id);
});

it('adds the sale price as a second Stripe price and on_sale chooses the one checkout uses', function () {
    [, , $listing, $path] = connectedPublisher();

    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'USD', 'sale_amount' => 2900, 'on_sale' => true])
        ->assertOk()
        ->assertJsonPath('price', ['model' => 'one_time', 'amount' => 4900, 'currency' => 'USD', 'on_sale' => true, 'sale_amount' => 2900, 'effective_amount' => 2900]);

    $price = Price::where('extension_uuid', $listing->uuid)->sole();
    $prices = stripe()->sent('post', '/v1/prices');
    expect($prices)->toHaveCount(2)
        ->and($prices[1]['params'])->toMatchArray(['unit_amount' => 2900, 'product' => $price->stripe_product_id])
        ->and($prices[1]['params']['metadata']['kind'])->toBe('sale')
        ->and($price->stripe_sale_price_id)->not->toBe($price->stripe_price_id)
        ->and($price->checkoutStripePriceId())->toBe($price->stripe_sale_price_id);

    // Ending the sale creates nothing: the regular price is already there.
    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'USD', 'sale_amount' => 2900, 'on_sale' => false])
        ->assertOk()
        ->assertJsonPath('price.on_sale', false)
        ->assertJsonPath('price.effective_amount', 4900);
    expect(stripe()->sent('post', '/v1/prices'))->toHaveCount(2)
        ->and($price->refresh()->checkoutStripePriceId())->toBe($price->stripe_price_id);
});

it('replaces and archives the Stripe price when the amount or currency changes', function () {
    [, , $listing, $path] = connectedPublisher();

    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'USD', 'sale_amount' => 2900])->assertOk();
    $first = Price::where('extension_uuid', $listing->uuid)->sole();

    $this->putJson($path, ['model' => 'one_time', 'amount' => 5900, 'currency' => 'EUR', 'sale_amount' => 3900])->assertOk();
    $second = $first->fresh();

    expect($second->stripe_product_id)->toBe($first->stripe_product_id)
        ->and($second->stripe_price_id)->not->toBe($first->stripe_price_id)
        ->and($second->stripe_sale_price_id)->not->toBe($first->stripe_sale_price_id)
        ->and(collect(stripe()->sent('post', '/v1/prices/price_\w+'))->map(fn ($r) => [basename($r['path']), $r['params']])->all())->toBe([
            [$first->stripe_price_id, ['active' => 'false']],
            [$first->stripe_sale_price_id, ['active' => 'false']],
        ])
        ->and(stripe()->sent('post', '/v1/prices')[2]['params']['currency'])->toBe('eur');

    // The product follows the listing's name.
    expect(stripe()->sent('post', '/v1/products/prod_\w+')[0]['params'])->toMatchArray(['name' => $listing->name, 'active' => 'true']);
});

it('archives the Stripe prices when a listing goes free', function () {
    [, , $listing, $path] = connectedPublisher();
    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'USD', 'sale_amount' => 2900])->assertOk();
    $paid = Price::where('extension_uuid', $listing->uuid)->sole();

    $this->putJson($path, ['model' => 'free'])->assertOk();

    $price = $paid->fresh();
    expect($price->stripe_price_id)->toBeNull()
        ->and($price->stripe_sale_price_id)->toBeNull()
        ->and($price->stripe_product_id)->toBe($paid->stripe_product_id)
        ->and(stripe()->sent('post', '/v1/prices/price_\w+'))->toHaveCount(2);
});

it('does not save a price Stripe refused', function () {
    [, , $listing, $path] = connectedPublisher();
    stripe()->on('post', '/v1/prices', stripeFixture('error_invalid_request'), 400);

    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'USD'])->assertStatus(502);

    expect($listing->price()->sole()->model)->toBe(Price::MODEL_FREE);
});

it('validates the sale amount', function () {
    [, , , $path] = connectedPublisher();

    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'USD', 'sale_amount' => 4900])
        ->assertStatus(422)
        ->assertJsonStructure(['fields' => ['sale_amount']]);
    expect(stripe()->requests)->toBe([]);
});
