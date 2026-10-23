<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Membership;
use App\Models\Organization;

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
 * A registered instance of the organization.
 */
function buyerInstance(Organization $organization, string $kind = Instance::KIND_SELF_HOSTED): Instance
{
    return Instance::factory()->create(['organization_uuid' => $organization->uuid, 'kind' => $kind]);
}
