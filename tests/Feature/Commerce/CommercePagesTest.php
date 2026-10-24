<?php

use App\Models\Account;
use App\Models\Checkout;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Purchase;
use Inertia\Testing\AssertableInertia as Assert;

it('renders Studio payouts with the account, balance and sales', function () {
    [$owner, $publisher, $listing] = sellableListing();
    [, $buyer] = buyerOrganization();
    Purchase::create(['extension_uuid' => $listing->uuid, 'buyer_organization_uuid' => $buyer->uuid, 'amount_total' => 4900, 'fee_amount' => 490, 'net_amount' => 4410, 'currency' => 'USD', 'status' => 'paid']);
    actingOnWebsite($owner);

    $this->get("/studio/payouts/{$publisher->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Payouts')
            ->where('organization.slug', $publisher->slug)
            ->where('payouts.connect.payout_status', 'enabled')
            ->where('payouts.balance.available.0.amount', 12880)
            ->where('transactions.data.0.net', 4410)
            ->where('transactions.totals.0.net', 4410)
            ->where('error', null)
            ->where('stripe', ['configured' => true, 'publishable_key' => 'pk_test_registry'])
            ->where('commerce.fee_bps', 1000));
});

it('shows the Connect status when Stripe refuses the balance', function () {
    [$owner, $publisher] = sellableListing();
    stripe()->on('get', '/v1/balance', ['error' => ['type' => 'invalid_request_error', 'message' => 'Account not found.']], 400);
    actingOnWebsite($owner);

    $this->get('/studio/payouts')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Payouts')
            ->where('organization.slug', $publisher->slug)
            ->where('payouts.connect.connected', true)
            ->where('error', 'Stripe could not complete the request: Account not found.'));
});

it('lists only organizations where the account sees money on Payouts, and every organization on Billing', function () {
    $account = Account::factory()->create();
    [, $organization] = buyerOrganization();
    $account->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_PUBLISHER]);
    actingOnWebsite($account);

    $this->get('/studio/payouts')->assertOk()->assertInertia(fn (Assert $page) => $page->where('organizations', [])->where('organization', null));
    $this->get("/studio/payouts/{$organization->slug}")->assertNotFound();
    // Billing shows its licences, not its purchases.
    $this->get("/account/billing/{$organization->slug}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Account/Billing')
        ->where('organization.slug', $organization->slug)
        ->where('purchases', null)
        ->where('licences', []));
});

it('renders account billing with purchases, licences and the returning hand-off', function () {
    [, , $listing] = sellableListing();
    [$account, $organization] = buyerOrganization(Membership::ROLE_BILLING);
    $purchase = Purchase::create(['extension_uuid' => $listing->uuid, 'buyer_organization_uuid' => $organization->uuid, 'buyer_account_uuid' => $account->uuid, 'amount_total' => 4900, 'fee_amount' => 490, 'net_amount' => 4410, 'currency' => 'USD', 'status' => 'paid', 'receipt_url' => 'https://pay.stripe.com/receipts/x']);
    Licence::create(['extension_uuid' => $listing->uuid, 'organization_uuid' => $organization->uuid, 'purchase_uuid' => $purchase->uuid, 'kind' => 'paid', 'max_activations' => 5]);
    $checkout = Checkout::create(['extension_uuid' => $listing->uuid, 'organization_uuid' => $organization->uuid, 'source' => 'website', 'status' => 'open', 'expires_at' => now()->addHour()]);
    actingOnWebsite($account);

    $this->get("/account/billing/{$organization->slug}?handoff={$checkout->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Billing')
            ->where('organization.slug', $organization->slug)
            ->where('purchases.0.id', $purchase->public_id)
            ->where('purchases.0.receipt_url', 'https://pay.stripe.com/receipts/x')
            ->where('licences.0.max_activations', 5)
            ->where('checkout.handoff', $checkout->public_id)
            ->where('checkout.status', 'open'));

    // Someone else's hand-off is not shown.
    [, $other] = buyerOrganization();
    $foreign = Checkout::create(['extension_uuid' => $listing->uuid, 'organization_uuid' => $other->uuid, 'source' => 'website']);
    $this->get("/account/billing/{$organization->slug}?handoff={$foreign->public_id}")->assertOk()->assertInertia(fn (Assert $page) => $page->where('checkout', null));
});

it('hides platform-side Stripe failures behind a generic message', function () {
    [$owner, $publisher] = sellableListing();
    stripe()->on('get', '/v1/balance', ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: sk_test_****']], 401);
    actingOnWebsite($owner);

    $this->get('/studio/payouts')->assertInertia(fn (Assert $page) => $page->where('error', 'Payments are unavailable right now. Try again in a few minutes.'));
});
