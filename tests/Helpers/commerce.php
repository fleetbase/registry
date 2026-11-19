<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Purchase;

/**
 * A published paid listing whose publisher organization has an onboarded Connect account and
 * whose price is mirrored to Stripe. Returns [publisher owner, publisher organization, listing].
 */
function sellableListing(int $amount = 4900, array $extension = [], array $price = []): array
{
    $owner = Account::factory()->create();
    $publisher = Organization::factory()->create([
        'stripe_connect_id' => 'acct_1QfLmTPublisher01',
        'payout_status' => Organization::PAYOUT_ENABLED,
        'meta' => ['stripe' => ['charges_enabled' => true, 'payouts_enabled' => true, 'details_submitted' => true, 'transfers' => 'active', 'card_payments' => 'active']],
    ]);
    $owner->organizations()->attach($publisher->uuid, ['role' => Membership::ROLE_OWNER]);

    $listing = Extension::factory()->published()->paid($amount)->withPackages()->for($publisher)->state($extension)->create();
    $listing->price->forceFill(array_merge([
        'stripe_product_id' => 'prod_QfLmTRouteOptimizer',
        'stripe_price_id' => 'price_1QfLmTRegular0001',
    ], $price))->save();

    return [$owner, $publisher, $listing->fresh(['price', 'organization'])];
}

/**
 * A buyer account and organization with the given role. Returns [account, organization].
 */
function buyerOrganization(string $role = Membership::ROLE_OWNER): array
{
    $account = Account::factory()->create();
    $organization = Organization::factory()->create();
    $account->organizations()->attach($organization->uuid, ['role' => $role]);

    return [$account, $organization];
}

/**
 * A registered instance of the organization, at `console.example` unless told otherwise.
 */
function buyerInstance(Organization $organization, string $kind = Instance::KIND_SELF_HOSTED, ?string $host = 'https://console.example'): Instance
{
    return Instance::factory()->create(['organization_uuid' => $organization->uuid, 'kind' => $kind, 'host' => $host]);
}

/**
 * A purchase of the listing by the organization, as the webhook writes it.
 */
function recordedSale(Extension $listing, Organization $buyer, int $total = 5292, int $fee = 490, array $attributes = []): Purchase
{
    $purchase = Purchase::create(array_merge([
        'extension_uuid' => $listing->uuid,
        'buyer_organization_uuid' => $buyer->uuid,
        'amount_total' => $total,
        'fee_amount' => $fee,
        'net_amount' => $total - $fee,
        'currency' => 'USD',
        'status' => Purchase::STATUS_PAID,
        'stripe_checkout_session_id' => 'cs_test_'.uniqid(),
        'stripe_payment_intent_id' => 'pi_test_'.uniqid(),
        'receipt_url' => 'https://pay.stripe.com/receipts/payment/x',
        'meta' => ['amount_subtotal' => 4900, 'amount_tax' => 392, 'fee_rate_bps' => 1000, 'source' => 'website'],
    ], $attributes));
    Licence::create(['extension_uuid' => $listing->uuid, 'organization_uuid' => $buyer->uuid, 'purchase_uuid' => $purchase->uuid, 'kind' => Licence::KIND_PAID, 'max_activations' => 5, 'status' => Licence::STATUS_ACTIVE]);

    return $purchase;
}
