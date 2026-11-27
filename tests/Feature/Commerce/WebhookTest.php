<?php

use App\Mail\DisputeOpenedMail;
use App\Mail\PurchaseReceiptMail;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Licence;
use App\Models\Organization;
use App\Models\Purchase;
use App\Services\LicenceGate;
use Illuminate\Support\Facades\Mail;

/**
 * A started instance checkout: [checkout, buyer organization, listing, instance].
 */
function startedCheckout(int $amount = 4900): array
{
    [, , $listing] = sellableListing($amount);
    [, $organization] = buyerOrganization();
    $instance = buyerInstance($organization);
    actingAsInstance($instance);
    test()->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])->assertCreated();

    return [Checkout::sole(), $organization, $listing, $instance];
}

function completedSession(Checkout $checkout, array $overrides = []): array
{
    return stripeFixture('checkout_session', array_replace_recursive([
        'id' => $checkout->stripe_checkout_session_id,
        'client_reference_id' => $checkout->public_id,
        'metadata' => ['handoff' => $checkout->public_id],
    ], $overrides));
}

beforeEach(fn () => Mail::fake());

it('creates the purchase and a paid licence from checkout.session.completed', function () {
    [$checkout, $organization, $listing, $instance] = startedCheckout();

    stripeWebhook('checkout.session.completed', completedSession($checkout), ['id' => 'evt_completed_1'])
        ->assertOk()
        ->assertExactJson(['received' => true, 'event' => 'evt_completed_1', 'status' => 'processed']);

    $purchase = Purchase::sole();
    expect($purchase->extension_uuid)->toBe($listing->uuid)
        ->and($purchase->buyer_organization_uuid)->toBe($organization->uuid)
        ->and($purchase->amount_total)->toBe(5292)
        ->and($purchase->fee_amount)->toBe(490)
        ->and($purchase->net_amount)->toBe(4802)
        ->and($purchase->currency)->toBe('USD')
        ->and($purchase->status)->toBe(Purchase::STATUS_PAID)
        ->and($purchase->receipt_url)->toBe('https://pay.stripe.com/receipts/payment/CAcaFwoVYWNjdF8xUWZMbVQ')
        ->and($purchase->stripe_payment_intent_id)->toBe('pi_3QfLmTPayment0001')
        ->and($purchase->meta)->toMatchArray([
            'handoff' => $checkout->public_id,
            'source' => 'instance',
            'instance_uuid' => $instance->uuid,
            'amount_subtotal' => 4900,
            'amount_tax' => 392,
            'fee_rate_bps' => 1000,
            'customer_email' => 'buyer@globex.example',
            'stripe_charge_id' => 'ch_3QfLmTCharge00001',
            'stripe_account' => 'acct_1QfLmTPublisher01',
        ]);

    $licence = Licence::sole();
    expect($licence->organization_uuid)->toBe($organization->uuid)
        ->and($licence->kind)->toBe(Licence::KIND_PAID)
        ->and($licence->max_activations)->toBe(5)
        ->and($licence->purchase_uuid)->toBe($purchase->uuid)
        ->and($licence->isActive())->toBeTrue();

    expect($checkout->refresh()->status)->toBe(Checkout::STATUS_COMPLETE)
        ->and($checkout->purchase_uuid)->toBe($purchase->uuid)
        ->and(Event::where('external_id', 'evt_completed_1')->sole()->kind)->toBe('stripe.checkout.session.completed')
        ->and(stripe()->sent('get', '/v1/payment_intents/pi_3QfLmTPayment0001')[0]['params'])->toBe(['expand' => ['latest_charge']]);

    Mail::assertSent(PurchaseReceiptMail::class, fn ($mail) => $mail->hasTo('buyer@globex.example') && $mail->purchase->is($purchase));
});

it('lets the instance install the extension with the new licence', function () {
    [$checkout, , $listing, $instance] = startedCheckout();
    makeRelease($listing->packages()->where('kind', 'npm')->sole(), '1.0.0');

    $this->postJson('/v1/instances/me/installs', ['extension' => $listing->slug, 'version' => '1.0.0', 'status' => 'installing'])
        ->assertForbidden();

    stripeWebhook('checkout.session.completed', completedSession($checkout))->assertOk();

    $this->postJson('/v1/instances/me/installs', ['extension' => $listing->slug, 'version' => '1.0.0', 'status' => 'installing'])
        ->assertCreated()
        ->assertJsonPath('activation.licence.kind', 'paid');
});

it('handles each event id once', function () {
    [$checkout] = startedCheckout();
    $session = completedSession($checkout);

    stripeWebhook('checkout.session.completed', $session, ['id' => 'evt_dup'])->assertOk()->assertJsonPath('status', 'processed');
    stripeWebhook('checkout.session.completed', $session, ['id' => 'evt_dup'])->assertOk()->assertJsonPath('status', 'duplicate');
    // A different event for the same session (Stripe can send both) creates nothing new either.
    stripeWebhook('checkout.session.async_payment_succeeded', $session, ['id' => 'evt_async'])->assertOk();

    expect(Purchase::count())->toBe(1)
        ->and(Licence::count())->toBe(1)
        ->and(Event::whereNotNull('external_id')->count())->toBe(2);
    Mail::assertSentCount(1);
});

it('waits for async_payment_succeeded when the session is not paid yet', function () {
    [$checkout] = startedCheckout();

    stripeWebhook('checkout.session.completed', completedSession($checkout, ['payment_status' => 'unpaid']))->assertOk();
    expect(Purchase::count())->toBe(0)->and($checkout->refresh()->status)->toBe(Checkout::STATUS_OPEN);

    stripeWebhook('checkout.session.async_payment_succeeded', completedSession($checkout))->assertOk();
    expect(Purchase::count())->toBe(1)->and($checkout->refresh()->status)->toBe(Checkout::STATUS_COMPLETE);
});

it('rolls back and answers 500 when handling fails, so Stripe retries', function () {
    [$checkout] = startedCheckout();
    stripe()->on('get', '/v1/payment_intents/(pi_\w+)', ['error' => ['type' => 'invalid_request_error', 'message' => 'No such payment_intent.']], 404, times: 1);

    stripeWebhook('checkout.session.completed', completedSession($checkout), ['id' => 'evt_retry'])->assertStatus(500);
    expect(Purchase::count())->toBe(0)->and(Event::where('external_id', 'evt_retry')->exists())->toBeFalse();

    stripeWebhook('checkout.session.completed', completedSession($checkout), ['id' => 'evt_retry'])->assertOk()->assertJsonPath('status', 'processed');
    expect(Purchase::count())->toBe(1);
});

it('refuses a bad or stale signature', function () {
    [$checkout] = startedCheckout();

    stripeWebhook('checkout.session.completed', completedSession($checkout), [], 'whsec_wrong')
        ->assertStatus(400)
        ->assertExactJson(['errors' => ['The Stripe signature is invalid.']]);
    stripeWebhook('checkout.session.completed', completedSession($checkout), [], STRIPE_TEST_WEBHOOK_SECRET, time() - 3600)->assertStatus(400);

    $this->call('POST', '/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')->assertStatus(400);

    expect(Purchase::count())->toBe(0)->and(Event::whereNotNull('external_id')->count())->toBe(0);
});

it('accepts the Connect endpoint secret and ignores events it does not handle', function () {
    stripeWebhook('customer.created', ['id' => 'cus_1', 'object' => 'customer'], [], 'whsec_test_connect')
        ->assertOk()
        ->assertJsonPath('status', 'ignored');
});

it('answers 503 until a webhook secret is set', function () {
    config(['services.stripe.webhook_secrets' => []]);

    stripeWebhook('customer.created', ['id' => 'cus_1', 'object' => 'customer'])->assertStatus(503);
});

it('marks the checkout expired on checkout.session.expired', function () {
    [$checkout] = startedCheckout();

    stripeWebhook('checkout.session.expired', completedSession($checkout, ['status' => 'expired', 'payment_status' => 'unpaid']))->assertOk();

    expect($checkout->refresh()->status)->toBe(Checkout::STATUS_EXPIRED);
    $this->getJson("/v1/instances/me/purchases/{$checkout->public_id}")->assertJsonPath('status', 'expired');
});

it('marks the purchase refunded and revokes the licence on a full refund', function () {
    [$checkout, , $listing] = startedCheckout();
    makeRelease($listing->packages()->where('kind', 'npm')->sole(), '1.0.0');
    stripeWebhook('checkout.session.completed', completedSession($checkout))->assertOk();
    $activation = Licence::sole()->activations()->create(['instance_uuid' => $checkout->instance_uuid, 'activated_at' => now()]);

    // A partial refund is recorded and changes nothing else.
    stripeWebhook('charge.refunded', stripeFixture('charge', ['amount_refunded' => 1000, 'refunded' => false]))->assertOk();
    expect(Purchase::sole()->status)->toBe(Purchase::STATUS_PAID)
        ->and(Purchase::sole()->meta['amount_refunded'])->toBe(1000)
        ->and(Licence::sole()->isActive())->toBeTrue();

    stripeWebhook('charge.refunded', stripeFixture('charge'))->assertOk();

    $purchase = Purchase::sole();
    $licence = Licence::sole();
    expect($purchase->status)->toBe(Purchase::STATUS_REFUNDED)
        ->and($purchase->meta['amount_refunded'])->toBe(5292)
        ->and($licence->status)->toBe(Licence::STATUS_REVOKED)
        ->and($licence->meta['revoked_reason'])->toBe('refunded')
        // Grace: the running instance keeps its seat.
        ->and($activation->refresh()->deactivated_at)->toBeNull()
        ->and(Event::where('kind', 'purchase.refunded')->count())->toBe(1);

    // New installs and package downloads stop.
    $this->postJson('/v1/instances/me/installs', ['extension' => $listing->slug, 'version' => '1.0.0', 'status' => 'installing'])->assertForbidden();
    expect(app(LicenceGate::class)->hasActiveLicence($listing, [$purchase->buyer_organization_uuid]))->toBeFalse();
});

it('ignores refunds and disputes of charges the registry did not sell', function () {
    stripeWebhook('charge.refunded', stripeFixture('charge', ['payment_intent' => 'pi_other']))->assertOk()->assertJsonPath('status', 'processed');
    stripeWebhook('charge.dispute.created', stripeFixture('dispute', ['payment_intent' => 'pi_other']))->assertOk();

    expect(Purchase::count())->toBe(0);
});

it('flags a disputed purchase, revokes its licence and tells staff', function () {
    config(['registry.commerce.notify' => ['finance@fleetbase.example']]);
    [$checkout] = startedCheckout();
    stripeWebhook('checkout.session.completed', completedSession($checkout))->assertOk();

    stripeWebhook('charge.dispute.created', stripeFixture('dispute'))->assertOk();

    $purchase = Purchase::sole();
    expect($purchase->status)->toBe(Purchase::STATUS_DISPUTED)
        ->and($purchase->meta['dispute'])->toMatchArray(['id' => 'dp_1QfLmTDispute0001', 'reason' => 'fraudulent', 'status' => 'needs_response', 'amount' => 5292])
        ->and(Licence::sole()->status)->toBe(Licence::STATUS_REVOKED)
        ->and(Licence::sole()->meta['revoked_reason'])->toBe('disputed');
    Mail::assertSent(DisputeOpenedMail::class, fn ($mail) => $mail->hasTo('finance@fleetbase.example'));
});

it('keeps payout_status from account.updated', function () {
    $organization = Organization::factory()->create(['stripe_connect_id' => 'acct_1QfLmTPublisher01', 'payout_status' => Organization::PAYOUT_ENABLED]);

    stripeWebhook('account.updated', stripeFixture('account_pending', ['details_submitted' => true]), ['account' => 'acct_1QfLmTPublisher01'], 'whsec_test_connect')->assertOk();

    $organization->refresh();
    expect($organization->payout_status)->toBe(Organization::PAYOUT_RESTRICTED)
        ->and($organization->canReceivePayments())->toBeFalse()
        ->and($organization->stripeState()['past_due'])->toBe(['external_account', 'tos_acceptance.date', 'tos_acceptance.ip']);

    stripeWebhook('account.updated', stripeFixture('account'))->assertOk();
    expect($organization->refresh()->payout_status)->toBe(Organization::PAYOUT_ENABLED)
        ->and($organization->canReceivePayments())->toBeTrue();
});
