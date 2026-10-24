<?php

use App\Models\Event;
use App\Models\Price;
use App\Models\Release;
use App\Models\Token;
use App\Releases\ReviewPolicy;
use Illuminate\Support\Facades\Mail;

it('keeps paid pricing closed until the organization has Stripe Connect', function () {
    [$account, $organization, $listing] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $path = '/v1/publisher/extensions/'.$listing->slug.'/price';

    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'usd'])
        ->assertStatus(422)
        ->assertExactJson(['errors' => ["Set up payouts for {$organization->name} before charging for an extension."]]);

    $this->putJson($path, ['model' => 'free'])
        ->assertOk()
        ->assertJsonPath('price.model', 'free')
        ->assertJsonPath('price.amount', 0);

    expect($listing->price()->sole()->model)->toBe(Price::MODEL_FREE);
});

it('sets a one-time price for an organization with Stripe Connect', function () {
    fakeStripe();
    [$account, $organization, $listing] = publisher();
    $organization->forceFill(['stripe_connect_id' => 'acct_123'])->save();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $path = '/v1/publisher/extensions/'.$listing->slug.'/price';

    $this->putJson($path, ['model' => 'one_time'])->assertStatus(422)->assertJsonStructure(['fields' => ['amount', 'currency']]);
    $this->putJson($path, ['model' => 'one_time', 'amount' => 0, 'currency' => 'USD'])->assertStatus(422);
    $this->putJson($path, ['model' => 'one_time', 'amount' => 49.5, 'currency' => 'USD'])->assertStatus(422);
    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'US'])->assertStatus(422);
    $this->putJson($path, ['model' => 'subscription', 'amount' => 4900, 'currency' => 'USD'])->assertStatus(422);

    $this->putJson($path, ['model' => 'one_time', 'amount' => 4900, 'currency' => 'eur'])
        ->assertOk()
        ->assertJsonPath('price', ['model' => 'one_time', 'amount' => 4900, 'currency' => 'EUR', 'on_sale' => false, 'sale_amount' => null, 'effective_amount' => 4900]);

    $this->putJson($path, ['model' => 'free'])->assertOk()->assertJsonPath('price.amount', 0);
    expect(Price::where('extension_uuid', $listing->uuid)->count())->toBe(1)
        ->and(Event::where('kind', 'extension.price_changed')->count())->toBe(2);
});

it('sends the next release to review after a price change', function () {
    Mail::fake();
    fakeStripe();
    [$account, $organization, $listing] = publisher();
    $organization->forceFill(['stripe_connect_id' => 'acct_123'])->save();
    publishedRelease($account, $listing, '1.0.0');

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->putJson('/v1/publisher/extensions/'.$listing->slug.'/price', ['model' => 'one_time', 'amount' => 1500, 'currency' => 'USD'])->assertOk();

    uploadRelease($account, $listing, '1.1.0', [], ['submit' => '1'])
        ->assertCreated()
        ->assertJsonPath('status', Release::STATUS_SUBMITTED)
        ->assertJsonPath('review.required', true)
        ->assertJsonPath('review.reasons.'.ReviewPolicy::PRICING, 'Pricing changed from free to 15.00 USD (one_time).');
});
