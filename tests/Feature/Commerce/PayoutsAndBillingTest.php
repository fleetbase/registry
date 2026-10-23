<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\FacilitatorFee;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Purchase;
use Illuminate\Support\Carbon;

it('shows the publisher balance, schedule and payouts from the connected account', function () {
    [$owner, $publisher] = sellableListing();
    actingAsAccount($owner);

    $this->getJson("/v1/organizations/{$publisher->slug}/payouts")
        ->assertOk()
        ->assertJsonPath('connect.payout_status', 'enabled')
        ->assertJsonPath('balance', [
            'available' => [['amount' => 12880, 'currency' => 'USD']],
            'pending' => [['amount' => 4410, 'currency' => 'USD']],
            'in_transit' => [['amount' => 8820, 'currency' => 'USD']],
        ])
        ->assertJsonPath('schedule', ['interval' => 'weekly', 'delay_days' => 2, 'weekly_anchor' => 'friday', 'monthly_anchor' => null])
        ->assertJsonPath('payouts.0.id', 'po_1QfLmTPayout00002')
        ->assertJsonPath('payouts.0.status', 'in_transit')
        ->assertJsonPath('payouts.1.status', 'paid');

    foreach (['/v1/balance', '/v1/payouts'] as $path) {
        expect(FakeStripeHttpClient::header(stripe()->sent('get', $path)[0], 'Stripe-Account'))->toBe('acct_1QfLmTPublisher01');
    }
});

it('answers without Stripe for an organization that has no account', function () {
    [$account, $organization] = buyerOrganization();
    actingAsAccount($account);

    $this->getJson("/v1/organizations/{$organization->slug}/payouts")
        ->assertOk()
        ->assertJsonPath('connect.connected', false)
        ->assertJsonPath('balance', null);
    expect(stripe()->requests)->toBe([]);
});

it('lists sales with gross, fee, net, status and customer, filtered and totalled', function () {
    [$owner, $publisher, $listing] = sellableListing();
    [, $globex] = buyerOrganization();
    [, $initech] = buyerOrganization();
    $this->travelTo(Carbon::parse('2026-10-01 12:00'));
    $old = recordedSale($listing, $globex);
    $this->travelTo(Carbon::parse('2026-10-20 12:00'));
    $refunded = recordedSale($listing, $initech, 5292, 490, ['status' => Purchase::STATUS_REFUNDED, 'meta' => ['amount_refunded' => 5292]]);
    // Someone else's sale never shows.
    [, , $otherListing] = sellableListing();
    recordedSale($otherListing, $globex);
    actingAsAccount($owner);

    $this->getJson("/v1/organizations/{$publisher->slug}/payouts/transactions")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $refunded->public_id)
        ->assertJsonPath('data.0.customer.name', $initech->name)
        ->assertJsonPath('data.0.status', 'refunded')
        ->assertJsonPath('data.0.refunded', 5292)
        ->assertJsonPath('data.1', [
            'id' => $old->public_id,
            'extension' => ['id' => $listing->public_id, 'slug' => $listing->slug, 'name' => $listing->name],
            'customer' => ['id' => $globex->public_id, 'slug' => $globex->slug, 'name' => $globex->name],
            'gross' => 5292,
            'fee' => 490,
            'net' => 4802,
            'tax' => 392,
            'refunded' => 0,
            'fee_rate_bps' => 1000,
            'currency' => 'USD',
            'status' => 'paid',
            'created_at' => $old->created_at->toJSON(),
        ])
        ->assertJsonPath('totals', [['currency' => 'USD', 'count' => 1, 'gross' => 5292, 'fee' => 490, 'net' => 4802]])
        ->assertJsonPath('meta.total', 2);

    $this->getJson("/v1/organizations/{$publisher->slug}/payouts/transactions?from=2026-10-10")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $refunded->public_id);
    $this->getJson("/v1/organizations/{$publisher->slug}/payouts/transactions?status=paid")->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $old->public_id);
    $this->getJson("/v1/organizations/{$publisher->slug}/payouts/transactions?status=bogus")->assertStatus(422);
});

it('exports sales as CSV', function () {
    [$owner, $publisher, $listing] = sellableListing();
    [, $globex] = buyerOrganization();
    $this->travelTo(Carbon::parse('2026-10-20 12:00:00', 'UTC'));
    $purchase = recordedSale($listing, $globex);
    actingAsAccount($owner);

    $response = $this->get("/v1/organizations/{$publisher->slug}/payouts/transactions.csv")->assertOk();

    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain("{$publisher->slug}-transactions-2026-10-20.csv")
        ->and(array_map('str_getcsv', explode("\n", trim($response->streamedContent()))))->toBe([
            ['date', 'id', 'extension', 'customer', 'status', 'currency', 'gross', 'fee', 'net', 'tax', 'refunded'],
            ['2026-10-20T12:00:00+00:00', $purchase->public_id, $listing->name, $globex->name, 'paid', 'USD', '52.92', '4.90', '48.02', '3.92', '0.00'],
        ]);
});

it('keeps payouts and billing to owners, admins and billing members', function (string $role, int $status) {
    [, $publisher] = sellableListing();
    $member = Account::factory()->create();
    $member->organizations()->attach($publisher->uuid, ['role' => $role]);
    actingAsAccount($member);

    $this->getJson("/v1/organizations/{$publisher->slug}/payouts/transactions")->assertStatus($status);
    $this->getJson("/v1/organizations/{$publisher->slug}/billing/purchases")->assertStatus($status);
    $this->getJson("/v1/organizations/{$publisher->slug}/billing/licences")->assertStatus($status);
})->with([
    'billing' => [Membership::ROLE_BILLING, 200],
    'admin' => [Membership::ROLE_ADMIN, 200],
    'publisher' => [Membership::ROLE_PUBLISHER, 403],
    'member' => [Membership::ROLE_MEMBER, 403],
]);

it('lists what an organization bought, who bought it and the receipts', function () {
    [, , $listing] = sellableListing();
    [$account, $organization] = buyerOrganization(Membership::ROLE_BILLING);
    $purchase = recordedSale($listing, $organization, attributes: ['buyer_account_uuid' => $account->uuid]);
    actingAsAccount($account);

    $this->getJson("/v1/organizations/{$organization->slug}/billing/purchases")
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $purchase->public_id)
        ->assertJsonPath('0.extension.slug', $listing->slug)
        ->assertJsonPath('0.bought_by', ['id' => $account->public_id, 'name' => $account->name, 'email' => $account->email])
        ->assertJsonPath('0.amount_total', 5292)
        ->assertJsonPath('0.amount_subtotal', 4900)
        ->assertJsonPath('0.amount_tax', 392)
        ->assertJsonPath('0.receipt_url', 'https://pay.stripe.com/receipts/payment/x')
        ->assertJsonPath('0.licence.kind', 'paid')
        ->assertJsonMissingPath('0.fee_amount');
});

it('lists licences with the instances holding a seat', function () {
    [, , $listing] = sellableListing();
    [$account, $organization] = buyerOrganization();
    $purchase = recordedSale($listing, $organization);
    $instance = buyerInstance($organization);
    $release = makeRelease($listing->packages()->where('kind', 'npm')->sole(), '1.2.0');
    $licence = Licence::sole();
    $licence->activations()->create(['instance_uuid' => $instance->uuid, 'release_uuid' => $release->uuid, 'activated_at' => now()]);
    $licence->activations()->create(['instance_uuid' => buyerInstance($organization)->uuid, 'activated_at' => now(), 'deactivated_at' => now()]);
    $free = Licence::create(['extension_uuid' => Extension::factory()->create()->uuid, 'organization_uuid' => $organization->uuid, 'kind' => Licence::KIND_FREE, 'max_activations' => null]);
    actingAsAccount($account);

    $this->getJson("/v1/organizations/{$organization->slug}/billing/licences")
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.id', $licence->public_id)
        ->assertJsonPath('0.purchase', $purchase->public_id)
        ->assertJsonPath('0.seats_used', 1)
        ->assertJsonPath('0.max_activations', 5)
        ->assertJsonPath('0.activations.0.instance', ['id' => $instance->public_id, 'name' => $instance->name, 'host' => $instance->host, 'kind' => $instance->kind])
        ->assertJsonPath('0.activations.0.release', '1.2.0')
        ->assertJsonCount(1, '0.activations')
        ->assertJsonPath('1.id', $free->public_id)
        ->assertJsonPath('1.kind', 'free');
});

it('keeps the facilitator fee as staff-managed history', function () {
    $staff = staffAccount();
    actingAsAccount($staff);

    $this->getJson('/v1/admin/facilitator-fees')->assertOk()->assertJsonPath('current_rate_bps', 1000)->assertJsonPath('data', []);

    $this->postJson('/v1/admin/facilitator-fees', ['rate_bps' => 1200, 'note' => 'Q1 change'])
        ->assertCreated()
        ->assertJsonPath('rate_bps', 1200)
        ->assertJsonPath('created_by.id', $staff->public_id);
    $this->postJson('/v1/admin/facilitator-fees', ['rate_bps' => 1500, 'effective_from' => now()->addMonth()->toIso8601String()])->assertCreated();
    $this->postJson('/v1/admin/facilitator-fees', ['rate_bps' => 500, 'effective_from' => now()->subDay()->toIso8601String()])
        ->assertStatus(422)
        ->assertJsonPath('fields.effective_from.0', 'A fee change cannot take effect in the past.');
    $this->postJson('/v1/admin/facilitator-fees', ['rate_bps' => 9000])->assertStatus(422);

    $this->getJson('/v1/admin/facilitator-fees')->assertOk()->assertJsonPath('current_rate_bps', 1200)->assertJsonCount(2, 'data');
    expect(FacilitatorFee::rateAt(now()->addMonths(2)))->toBe(1500)
        ->and(FacilitatorFee::rateAt(now()->subYear()))->toBe(1000);

    [$account] = buyerOrganization();
    actingAsAccount($account);
    $this->getJson('/v1/admin/facilitator-fees')->assertForbidden();
});
