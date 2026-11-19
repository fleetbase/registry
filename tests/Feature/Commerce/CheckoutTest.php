<?php

use App\Models\Checkout;
use App\Models\Extension;
use App\Models\FacilitatorFee;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Organization;

it('starts a hosted Checkout Session from an instance with a destination charge, the fee, tax and metadata', function () {
    [, $publisher, $listing] = sellableListing(4900);
    [, $organization] = buyerOrganization();
    $instance = buyerInstance($organization);
    actingAsInstance($instance);

    $response = $this->postJson('/v1/instances/me/purchases/checkout', [
        'extension' => packageName($listing, 'npm'),
        'return_url' => 'https://console.example/extensions/purchased?x=1',
        'email' => 'ops@globex.example',
    ])->assertCreated();

    $checkout = Checkout::sole();
    $response->assertJsonPath('handoff', $checkout->public_id)
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('amount', 4900)
        ->assertJsonPath('currency', 'USD')
        ->assertJsonPath('extension.slug', $listing->slug)
        ->assertJsonPath('organization.id', $organization->public_id)
        ->assertJsonPath('url', 'https://checkout.stripe.com/c/pay/'.$checkout->stripe_checkout_session_id);

    $request = stripe()->sent('post', '/v1/checkout/sessions')[0];
    $params = $request['params'];
    $metadata = [
        'handoff' => $checkout->public_id,
        'source' => 'instance',
        'extension' => $listing->public_id,
        'extension_uuid' => $listing->uuid,
        'organization' => $organization->public_id,
        'organization_uuid' => $organization->uuid,
        'instance' => $instance->public_id,
        'fee_rate_bps' => '1000',
    ];

    expect($params['mode'])->toBe('payment')
        ->and($params['line_items'])->toBe([['price' => 'price_1QfLmTRegular0001', 'quantity' => 1]])
        ->and($params['success_url'])->toBe("https://console.example/extensions/purchased?x=1&handoff={$checkout->public_id}")
        ->and($params['cancel_url'])->toBe("https://console.example/extensions/purchased?x=1&handoff={$checkout->public_id}&cancelled=1")
        ->and($params['client_reference_id'])->toBe($checkout->public_id)
        ->and($params['customer_email'])->toBe('ops@globex.example')
        ->and($params['metadata'])->toBe($metadata)
        ->and($params['payment_intent_data'])->toBe([
            'application_fee_amount' => 490,
            'transfer_data' => ['destination' => 'acct_1QfLmTPublisher01'],
            'on_behalf_of' => 'acct_1QfLmTPublisher01',
            'description' => "{$listing->name} licence",
            'metadata' => $metadata,
        ])
        ->and($params['automatic_tax'])->toBe(['enabled' => 'true', 'liability' => ['type' => 'account', 'account' => 'acct_1QfLmTPublisher01']])
        ->and($params['tax_id_collection'])->toBe(['enabled' => 'true'])
        ->and((int) $params['expires_at'])->toBe($checkout->expires_at->getTimestamp())
        ->and(FakeStripeHttpClient::header($request, 'Idempotency-Key'))->toBe('checkout:'.$checkout->uuid);

    expect($checkout->fee_amount)->toBe(490)
        ->and($checkout->fee_rate_bps)->toBe(1000)
        ->and($checkout->instance_uuid)->toBe($instance->uuid)
        ->and($checkout->price_snapshot['stripe_price_id'])->toBe('price_1QfLmTRegular0001');
});

it('charges the sale price while the sale is on', function () {
    [, , $listing] = sellableListing(4900, [], ['sale_amount' => 2900, 'on_sale' => true, 'stripe_sale_price_id' => 'price_1QfLmTSale0000001']);
    [, $organization] = buyerOrganization();
    actingAsInstance(buyerInstance($organization));

    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])
        ->assertCreated()
        ->assertJsonPath('amount', 2900);

    $params = stripe()->sent('post', '/v1/checkout/sessions')[0]['params'];
    expect($params['line_items'][0]['price'])->toBe('price_1QfLmTSale0000001')
        ->and($params['payment_intent_data']['application_fee_amount'])->toBe(290);
});

it('uses the facilitator fee in effect when the checkout starts', function () {
    [, , $listing] = sellableListing(4900);
    [, $organization] = buyerOrganization();
    actingAsInstance(buyerInstance($organization));
    FacilitatorFee::create(['rate_bps' => 1500, 'effective_from' => now()->subDay()]);
    FacilitatorFee::create(['rate_bps' => 2000, 'effective_from' => now()->addWeek()]);

    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])->assertCreated();

    expect(stripe()->sent('post', '/v1/checkout/sessions')[0]['params']['payment_intent_data']['application_fee_amount'])->toBe(735)
        ->and(Checkout::sole()->fee_rate_bps)->toBe(1500);
});

it('refuses checkouts that cannot or need not happen', function (Closure $arrange, int $status, string $message) {
    [, $publisher, $listing] = sellableListing(4900);
    [, $organization] = buyerOrganization();
    $instance = buyerInstance($organization);
    $message = $arrange($listing, $organization, $instance, $publisher) ?? $message;
    actingAsInstance($instance->refresh());

    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])
        ->assertStatus($status)
        ->assertExactJson(['errors' => [$message]]);

    expect(Checkout::count())->toBe(0)
        ->and(stripe()->sent('post', '/v1/checkout/sessions'))->toBe([]);
})->with([
    'not published' => [function ($listing) {
        $listing->forceFill(['status' => Extension::STATUS_SUSPENDED])->save();
    }, 422, 'This extension is not published.'],
    'free' => [function ($listing) {
        $listing->price->forceFill(['model' => 'free', 'amount' => 0])->save();

        return "{$listing->name} is free: install it without buying.";
    }, 422, ''],
    'wrong hosting' => [function ($listing) {
        $listing->forceFill(['hosting' => Extension::HOSTING_CLOUD])->save();
    }, 422, 'This extension is only available for cloud instances.'],
    'publisher buys its own' => [function ($listing, $organization, $instance, $publisher) {
        $instance->forceFill(['organization_uuid' => $publisher->uuid])->save();

        return "{$publisher->name} publishes {$listing->name} and already has access to it.";
    }, 409, ''],
    'payouts not ready' => [function ($listing, $organization, $instance, $publisher) {
        $publisher->forceFill(['meta' => ['stripe' => ['transfers' => 'inactive']], 'payout_status' => Organization::PAYOUT_RESTRICTED])->save();

        return "{$listing->name} cannot be bought right now: its publisher has not finished setting up payouts.";
    }, 422, ''],
]);

it('reads imported accounts and mirrors imported prices before the first checkout', function () {
    [, $publisher, $listing] = sellableListing(4900, [], ['stripe_price_id' => null]);
    $publisher->forceFill(['meta' => null, 'payout_status' => null])->save();
    [, $organization] = buyerOrganization();
    actingAsInstance(buyerInstance($organization));

    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])->assertCreated();

    $price = $listing->price->fresh();
    expect(stripe()->sent('get', '/v1/accounts/acct_1QfLmTPublisher01'))->toHaveCount(1)
        ->and($publisher->refresh()->payout_status)->toBe(Organization::PAYOUT_ENABLED)
        ->and($price->stripe_price_id)->toStartWith('price_test')
        ->and(stripe()->sent('post', '/v1/checkout/sessions')[0]['params']['line_items'][0]['price'])->toBe($price->stripe_price_id);
});

it('sells another licence to an organization that already holds one', function () {
    [, , $listing] = sellableListing();
    [, $organization] = buyerOrganization();
    Licence::create(['extension_uuid' => $listing->uuid, 'organization_uuid' => $organization->uuid, 'kind' => Licence::KIND_PAID, 'max_activations' => 5, 'status' => Licence::STATUS_ACTIVE]);
    actingAsInstance(buyerInstance($organization));

    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])->assertCreated();
});

it('sends buyers back only to the instance host or its domain', function (?string $host, string $url, bool $allowed) {
    [, , $listing] = sellableListing();
    [, $organization] = buyerOrganization();
    actingAsInstance(buyerInstance($organization, host: $host));

    $response = $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => $url]);

    $allowed ? $response->assertCreated() : $response->assertStatus(422);
})->with([
    'same host' => ['https://api.acme.com', 'https://api.acme.com/console/extensions', true],
    'sibling subdomain' => ['https://api.acme.com', 'https://console.acme.com/extensions', true],
    'parent domain' => ['api.acme.com:8000', 'https://acme.com/x', true],
    'other domain' => ['https://api.acme.com', 'https://evil.example/x', false],
    'look-alike suffix' => ['https://api.acme.com', 'https://notacme.com/x', false],
    'localhost with port' => ['http://localhost:8000', 'http://localhost:4200/extensions', true],
    'localhost elsewhere' => ['http://localhost:8000', 'http://127.0.0.1:4200/x', false],
    'no registered host' => [null, 'https://console.example/x', false],
]);

it('checks the cancel URL as well', function () {
    [, , $listing] = sellableListing();
    [, $organization] = buyerOrganization();
    actingAsInstance(buyerInstance($organization));

    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x', 'cancel_url' => 'https://evil.example/x'])
        ->assertStatus(422)
        ->assertExactJson(['errors' => ["Return URLs must be on this instance's host (console.example) or its domain."]]);
    expect(Checkout::count())->toBe(0);
});

it('validates the instance checkout request', function () {
    [, $organization] = buyerOrganization();
    actingAsInstance(buyerInstance($organization));

    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => 'x', 'return_url' => 'javascript:alert(1)'])
        ->assertStatus(422)
        ->assertJsonStructure(['fields' => ['return_url']]);
    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => 'missing', 'return_url' => 'https://console.example/x'])
        ->assertNotFound();
    $this->withHeader('Authorization', 'Bearer flbi_nope')
        ->postJson('/v1/instances/me/purchases/checkout', ['extension' => 'x', 'return_url' => 'https://console.example/x'])
        ->assertUnauthorized();
});

it('polls a hand-off from the instance until the purchase lands', function () {
    [, , $listing] = sellableListing();
    [, $organization] = buyerOrganization();
    $instance = buyerInstance($organization);
    actingAsInstance($instance);

    $handoff = $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])->json('handoff');

    $this->getJson("/v1/instances/me/purchases/{$handoff}")
        ->assertOk()
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('purchase', null);

    $session = Checkout::sole()->stripe_checkout_session_id;
    stripeWebhook('checkout.session.completed', stripeFixture('checkout_session', ['id' => $session]))->assertOk();

    $this->getJson("/v1/instances/me/purchases/{$handoff}")
        ->assertOk()
        ->assertJsonPath('status', 'complete')
        ->assertJsonPath('url', null)
        ->assertJsonPath('purchase.status', 'paid')
        ->assertJsonPath('purchase.amount_total', 5292)
        ->assertJsonPath('purchase.receipt_url', 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xUWZMbVQ')
        ->assertJsonPath('purchase.licence.kind', 'paid')
        ->assertJsonPath('purchase.licence.max_activations', 5);

    // The licence carries the receipt, so a console keeps it after re-registering or clearing its cache.
    $this->getJson('/v1/instances/me')
        ->assertOk()
        ->assertJsonPath('licences.0.kind', 'paid')
        ->assertJsonPath('licences.0.receipt_url', 'https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xUWZMbVQ');

    // Another organization's instance cannot see it.
    [, $other] = buyerOrganization();
    actingAsInstance(buyerInstance($other));
    $this->getJson("/v1/instances/me/purchases/{$handoff}")->assertNotFound();
});

it('reports an open hand-off past its expiry as expired', function () {
    [, , $listing] = sellableListing();
    [, $organization] = buyerOrganization();
    actingAsInstance(buyerInstance($organization));
    $handoff = $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])->json('handoff');

    $this->travel(2)->hours();

    $this->getJson("/v1/instances/me/purchases/{$handoff}")->assertOk()->assertJsonPath('status', 'expired')->assertJsonPath('url', null);
});

it('starts a website checkout for a signed-in buyer organization', function () {
    config(['registry.public_url' => 'https://registry.example']);
    [, , $listing] = sellableListing(4900);
    [$account, $organization] = buyerOrganization(Membership::ROLE_BILLING);
    actingAsAccount($account);

    $response = $this->postJson('/v1/purchases/checkout', ['extension' => $listing->slug, 'organization' => $organization->slug])
        ->assertCreated()
        ->assertJsonPath('status', 'open');

    $handoff = $response->json('handoff');
    $params = stripe()->sent('post', '/v1/checkout/sessions')[0]['params'];
    expect($params['success_url'])->toBe("https://registry.example/account/billing/{$organization->slug}?handoff={$handoff}")
        ->and($params['cancel_url'])->toBe("https://registry.example/extensions/{$listing->slug}?handoff={$handoff}&cancelled=1")
        ->and($params['customer_email'])->toBe($account->email)
        ->and($params['metadata']['source'])->toBe('website')
        ->and($params['metadata']['account'])->toBe($account->public_id)
        ->and($params['metadata'])->not->toHaveKey('instance');

    $this->getJson("/v1/purchases/{$handoff}")->assertOk()->assertJsonPath('handoff', $handoff);
    expect(Checkout::sole()->account_uuid)->toBe($account->uuid);
});

it('keeps website checkout to owners, admins and billing members of the buyer', function () {
    [, , $listing] = sellableListing();
    [$account, $organization] = buyerOrganization(Membership::ROLE_PUBLISHER);
    actingAsAccount($account);

    $this->postJson('/v1/purchases/checkout', ['extension' => $listing->slug, 'organization' => $organization->slug])
        ->assertForbidden()
        ->assertExactJson(['errors' => ['Only organization owners, admins and billing members can buy extensions.']]);

    $stranger = Organization::factory()->create();
    $this->postJson('/v1/purchases/checkout', ['extension' => $listing->slug, 'organization' => $stranger->slug])->assertNotFound();

    // A hand-off of another organization is not found.
    [, $other] = buyerOrganization();
    $checkout = Checkout::create(['extension_uuid' => $listing->uuid, 'organization_uuid' => $other->uuid, 'source' => 'website']);
    $this->getJson("/v1/purchases/{$checkout->public_id}")->assertNotFound();
});
