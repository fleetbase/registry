<?php

use App\Models\Event;
use App\Models\Extension;
use App\Models\Licence;
use App\Models\Organization;
use App\Models\Purchase;
use App\Models\Token;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Two publishers' sales over September and October 2026: a paid sale, a full refund, a partial
 * refund, a dispute, a sale in euros, and an old sale outside the default range. Returns
 * [northwind, initech, the purchases by name].
 */
function financeLedger(): array
{
    [, $northwind, $optimizer] = sellableListing(extension: ['name' => 'Route Optimizer']);
    $initech = Organization::factory()->create(['name' => 'Initech', 'stripe_connect_id' => 'acct_1QfLmTPublisher02']);
    $telematics = Extension::factory()->published()->paid(10000)->for($initech)->create(['name' => 'Telematics']);
    [, $globex] = buyerOrganization();

    $at = function (string $date, callable $make): Purchase {
        test()->travelTo(Carbon::parse($date));

        return $make();
    };
    $sales = [
        'old' => $at('2025-01-10 12:00', fn () => recordedSale($optimizer, $globex)),
        'partial' => $at('2026-09-15 12:00', fn () => recordedSale($optimizer, $globex, attributes: ['meta' => ['amount_refunded' => 1000]])),
        'paid' => $at('2026-10-01 12:00', fn () => recordedSale($optimizer, $globex)),
        'disputed' => $at('2026-10-05 12:00', fn () => recordedSale($telematics, $globex, 10000, 1000, [
            'status' => Purchase::STATUS_DISPUTED,
            'meta' => ['dispute' => ['id' => 'dp_1', 'reason' => 'fraudulent', 'status' => 'needs_response', 'amount' => 10000]],
        ])),
        'euros' => $at('2026-10-06 12:00', fn () => recordedSale($telematics, $globex, 2000, 200, ['currency' => 'EUR'])),
        'refunded' => $at('2026-10-20 12:00', fn () => recordedSale($optimizer, $globex, attributes: ['status' => Purchase::STATUS_REFUNDED, 'meta' => ['amount_refunded' => 5292]])),
    ];
    test()->travelTo(Carbon::parse('2026-10-23 09:00'));

    return [$northwind, $initech, $sales];
}

it('totals fee revenue, refunds and disputes per currency, month and publisher', function () {
    [$northwind, $initech] = financeLedger();
    actingAsAccount(staffAccount());

    // The partial refund of 10.00 returns 0.93 of the 4.90 fee (in proportion); the full refund all of it.
    $usd = ['currency' => 'USD', 'sales' => 4, 'gross' => 25876, 'refunds' => 2, 'refunded' => 6292, 'fee' => 2470, 'fee_refunded' => 583, 'fee_revenue' => 1887, 'publisher_net' => 17697, 'disputes' => 1, 'disputed' => 10000];
    $eur = ['currency' => 'EUR', 'sales' => 1, 'gross' => 2000, 'refunds' => 0, 'refunded' => 0, 'fee' => 200, 'fee_refunded' => 0, 'fee_revenue' => 200, 'publisher_net' => 1800, 'disputes' => 0, 'disputed' => 0];

    $this->getJson('/v1/admin/finance')
        ->assertOk()
        // Default: the last 12 months including this one, so the January 2025 sale is left out.
        ->assertJsonPath('from', '2025-11-01')
        ->assertJsonPath('to', '2026-10-23')
        ->assertJsonPath('totals', [$eur, $usd])
        ->assertJsonPath('months.0', ['month' => '2026-09', 'currency' => 'USD', 'sales' => 1, 'gross' => 5292, 'refunds' => 1, 'refunded' => 1000, 'fee' => 490, 'fee_refunded' => 93, 'fee_revenue' => 397, 'publisher_net' => 3895, 'disputes' => 0, 'disputed' => 0])
        ->assertJsonPath('months.1.month', '2026-10')
        ->assertJsonPath('months.1.currency', 'EUR')
        ->assertJsonPath('months.2.currency', 'USD')
        ->assertJsonPath('months.2.sales', 3)
        ->assertJsonCount(3, 'months')
        ->assertJsonPath('publishers.0.organization', ['id' => $initech->public_id, 'slug' => $initech->slug, 'name' => 'Initech'])
        ->assertJsonPath('publishers.0.currency', 'USD')
        ->assertJsonPath('publishers.0.fee_revenue', 1000)
        ->assertJsonPath('publishers.0.disputes', 1)
        ->assertJsonPath('publishers.1.organization.id', $northwind->public_id)
        ->assertJsonPath('publishers.1.fee_revenue', 887)
        ->assertJsonPath('publishers.1.refunds', 2)
        ->assertJsonPath('publishers.2.currency', 'EUR');

    $this->getJson('/v1/admin/finance?from=2026-10-01&to=2026-10-31')
        ->assertOk()
        ->assertJsonPath('totals.1.sales', 3)
        ->assertJsonPath('totals.1.fee_revenue', 1490)
        ->assertJsonCount(2, 'months');
    $this->getJson('/v1/admin/finance?from=2026-10-31&to=2026-10-01')->assertStatus(422);
    $this->getJson('/v1/admin/finance?from=nope')->assertStatus(422);
});

it('lists purchases for staff with what is left to refund', function () {
    [$northwind, , $sales] = financeLedger();
    actingAsAccount(staffAccount());

    $this->getJson('/v1/admin/finance/purchases')
        ->assertOk()
        ->assertJsonCount(5, 'data')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('data.0.id', $sales['refunded']->public_id)
        ->assertJsonPath('data.0.refundable', 0)
        ->assertJsonPath('data.0.fee_refunded', 490)
        ->assertJsonPath('data.1.status', 'paid')
        ->assertJsonPath('data.4.id', $sales['partial']->public_id)
        ->assertJsonPath('data.4.refunded', 1000)
        ->assertJsonPath('data.4.refundable', 4292)
        ->assertJsonPath('data.4.publisher', ['id' => $northwind->public_id, 'slug' => $northwind->slug, 'name' => $northwind->name])
        ->assertJsonPath('data.4.stripe.payment_intent', $sales['partial']->stripe_payment_intent_id);

    $this->getJson('/v1/admin/finance/purchases?status=disputed')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.dispute.reason', 'fraudulent')
        ->assertJsonPath('data.0.refundable', 0);
    $this->getJson("/v1/admin/finance/purchases?publisher={$northwind->slug}")->assertJsonCount(3, 'data');
    $this->getJson("/v1/admin/finance/purchases?publisher={$northwind->public_id}&from=2026-10-01")->assertJsonCount(2, 'data');
    $this->getJson('/v1/admin/finance/purchases?status=bogus')->assertStatus(422);
});

it('exports the months as CSV, overall or by publisher', function () {
    [$northwind, $initech] = financeLedger();
    actingAsAccount(staffAccount());

    $response = $this->get('/v1/admin/finance/monthly.csv?from=2026-09-01&to=2026-10-31')->assertOk();
    expect($response->headers->get('Content-Type'))->toStartWith('text/csv')
        ->and($response->headers->get('Content-Disposition'))->toContain('fleetbase-finance-2026-09-to-2026-10.csv')
        ->and(array_map('str_getcsv', explode("\n", trim($response->streamedContent()))))->toBe([
            ['month', 'currency', 'sales', 'gross', 'refunds', 'refunded', 'fee', 'fee_refunded', 'fee_revenue', 'publisher_net', 'disputes', 'disputed'],
            ['2026-09', 'USD', '1', '52.92', '1', '10.00', '4.90', '0.93', '3.97', '38.95', '0', '0.00'],
            ['2026-10', 'EUR', '1', '20.00', '0', '0.00', '2.00', '0.00', '2.00', '18.00', '0', '0.00'],
            ['2026-10', 'USD', '3', '205.84', '1', '52.92', '19.80', '4.90', '14.90', '138.02', '1', '100.00'],
        ]);

    $rows = array_map('str_getcsv', explode("\n", trim($this->get('/v1/admin/finance/monthly.csv?from=2026-09-01&to=2026-10-31&by=publisher')->assertOk()->streamedContent())));
    expect($rows[0])->toBe(['month', 'publisher', 'publisher_id', 'currency', 'sales', 'gross', 'refunds', 'refunded', 'fee', 'fee_refunded', 'fee_revenue', 'publisher_net', 'disputes', 'disputed'])
        ->and(array_map(fn ($row) => [$row[0], $row[2], $row[3], $row[10]], array_slice($rows, 1)))->toBe([
            ['2026-09', $northwind->public_id, 'USD', '3.97'],
            ['2026-10', $initech->public_id, 'USD', '10.00'],
            ['2026-10', $northwind->public_id, 'USD', '4.90'],
            ['2026-10', $initech->public_id, 'EUR', '2.00'],
        ]);
});

it('refunds through Stripe with the transfer reversed and the fee returned, and leaves the licence to the webhook', function () {
    [$owner, $publisher, $listing] = sellableListing();
    [, $globex] = buyerOrganization();
    $purchase = recordedSale($listing, $globex, attributes: ['stripe_payment_intent_id' => 'pi_3QfLmTPayment0001']);
    $staff = staffAccount();
    actingAsAccount($staff);

    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund", ['reason' => 'requested_by_customer', 'note' => 'Bought for the wrong organization'])
        ->assertStatus(202)
        ->assertJsonPath('refund.amount', 5292)
        ->assertJsonPath('refund.currency', 'USD')
        ->assertJsonPath('refund.status', 'succeeded')
        ->assertJsonPath('refund.reason', 'requested_by_customer')
        ->assertJsonPath('purchase.id', $purchase->public_id)
        ->assertJsonPath('purchase.status', 'paid');

    [$request] = stripe()->sent('post', '/v1/refunds');
    expect($request['params'])->toBe([
        'payment_intent' => 'pi_3QfLmTPayment0001',
        'amount' => 5292,
        'reason' => 'requested_by_customer',
        // stripe-php sends booleans as strings.
        'reverse_transfer' => 'true',
        'refund_application_fee' => 'true',
        'metadata' => ['purchase' => $purchase->public_id, 'refunded_by' => $staff->public_id, 'note' => 'Bought for the wrong organization'],
    ])
        ->and(FakeStripeHttpClient::header($request, 'Idempotency-Key'))->toStartWith("refund:{$purchase->uuid}:0:")
        // On the platform account, not the publisher's.
        ->and(FakeStripeHttpClient::header($request, 'Stripe-Account'))->toBeNull();

    $event = Event::query()->where('kind', 'purchase.refund_requested')->sole();
    expect($event->subject_uuid)->toBe($purchase->uuid)
        ->and($event->actor_uuid)->toBe($staff->uuid)
        ->and($event->payload)->toMatchArray(['amount' => 5292, 'currency' => 'USD', 'status' => 'succeeded', 'reason' => 'requested_by_customer', 'note' => 'Bought for the wrong organization']);

    // Nothing changes until Stripe confirms.
    expect($purchase->refresh()->status)->toBe(Purchase::STATUS_PAID)
        ->and(Licence::sole()->status)->toBe(Licence::STATUS_ACTIVE);

    stripeWebhook('charge.refunded', stripeFixture('charge'))->assertOk();
    expect($purchase->refresh()->status)->toBe(Purchase::STATUS_REFUNDED)
        ->and(Licence::sole()->status)->toBe(Licence::STATUS_REVOKED);

    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund")->assertStatus(409)->assertJsonPath('errors.0', 'This purchase is already refunded.');
});

it('refunds part of a purchase, up to what is left', function () {
    [, , $listing] = sellableListing();
    [, $globex] = buyerOrganization();
    $purchase = recordedSale($listing, $globex, attributes: ['meta' => ['amount_refunded' => 1000]]);
    actingAsAccount(staffAccount());

    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund", ['amount' => 4293])
        ->assertStatus(422)
        ->assertJsonPath('fields.amount.0', 'At most 42.92 USD can be refunded.');
    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund", ['amount' => 0])->assertStatus(422);
    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund", ['reason' => 'changed_mind'])->assertStatus(422);
    expect(stripe()->sent('post', '/v1/refunds'))->toBe([]);

    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund", ['amount' => 2000])->assertStatus(202)->assertJsonPath('refund.amount', 2000);
    // Without an amount, what is left.
    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund")->assertStatus(202)->assertJsonPath('refund.amount', 4292);

    $sent = stripe()->sent('post', '/v1/refunds');
    expect(array_column(array_column($sent, 'params'), 'amount'))->toBe([2000, 4292])
        ->and(FakeStripeHttpClient::header($sent[0], 'Idempotency-Key'))->toStartWith("refund:{$purchase->uuid}:1000:")
        ->and(FakeStripeHttpClient::header($sent[0], 'Idempotency-Key'))->not->toBe(FakeStripeHttpClient::header($sent[1], 'Idempotency-Key'));
});

it('refuses refunds Stripe would refuse, and passes on what Stripe says', function () {
    [, , $listing] = sellableListing();
    [, $globex] = buyerOrganization();
    $disputed = recordedSale($listing, $globex, attributes: ['status' => Purchase::STATUS_DISPUTED, 'meta' => ['dispute' => ['id' => 'dp_1', 'amount' => 5292]]]);
    $free = recordedSale($listing, $globex, attributes: ['stripe_payment_intent_id' => null]);
    $paid = recordedSale($listing, $globex);
    actingAsAccount(staffAccount());

    $this->postJson("/v1/admin/purchases/{$disputed->public_id}/refund")->assertStatus(409)->assertJsonPath('errors.0', fn ($message) => str_contains($message, 'disputed'));
    $this->postJson("/v1/admin/purchases/{$free->public_id}/refund")->assertStatus(422)->assertJsonPath('errors.0', 'This purchase has no Stripe payment to refund.');
    $this->postJson('/v1/admin/purchases/pur_missing/refund')->assertNotFound();

    stripe()->on('post', '/v1/refunds', ['error' => ['type' => 'invalid_request_error', 'code' => 'charge_already_refunded', 'message' => 'Charge ch_1 has already been refunded.']], 400);
    $this->postJson("/v1/admin/purchases/{$paid->public_id}/refund")
        ->assertStatus(502)
        ->assertJsonPath('errors.0', 'Stripe could not complete the request: Charge ch_1 has already been refunded.');
    expect(Event::query()->where('kind', 'purchase.refund_requested')->count())->toBe(0);

    config(['services.stripe.secret' => null]);
    $this->postJson("/v1/admin/purchases/{$paid->public_id}/refund")->assertStatus(503);
    // The report reads the registry's own purchases, so it works without Stripe.
    $this->getJson('/v1/admin/finance')->assertOk()->assertJsonPath('totals.0.sales', 3);
});

it('keeps finance to Fleetbase staff with the review scope', function () {
    [, , $listing] = sellableListing();
    [$account, $globex] = buyerOrganization();
    $purchase = recordedSale($listing, $globex);

    $this->getJson('/v1/admin/finance')->assertUnauthorized();

    actingAsAccount($account);
    $this->getJson('/v1/admin/finance')->assertForbidden();
    $this->getJson('/v1/admin/finance/purchases')->assertForbidden();
    $this->get('/v1/admin/finance/monthly.csv')->assertForbidden();
    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund")->assertForbidden();

    $this->flushHeaders();
    actingAsAccount(staffAccount(), [Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/admin/finance')->assertForbidden();
    $this->postJson("/v1/admin/purchases/{$purchase->public_id}/refund")->assertForbidden();
    expect(stripe()->sent('post', '/v1/refunds'))->toBe([]);
});

it('gives the staff finance page the report and the purchases', function () {
    [$northwind] = financeLedger();
    actingOnWebsite(staffAccount());

    $this->get('/review/finance')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Review/Finance')
            ->where('summary.from', '2025-11-01')
            ->has('summary.totals', 2)
            ->has('summary.publishers', 3)
            ->has('purchases.data', 5)
            ->where('stripe.configured', true)
            ->where('filters', [])
            ->has('counts'));

    $this->get("/review/finance?from=2026-10-01&to=2026-10-31&status=paid&publisher={$northwind->slug}")
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.from', '2026-10-01')
            ->where('filters', ['status' => 'paid', 'from' => '2026-10-01', 'to' => '2026-10-31', 'publisher' => $northwind->slug])
            ->has('purchases.data', 1));

    // Bad filters fall back to the defaults instead of an error page.
    $this->get('/review/finance?from=2026-13-45&to=yesterday&status=bogus')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('summary.from', '2025-11-01')->where('filters', []));

    [$account] = buyerOrganization();
    actingOnWebsite($account);
    $this->get('/review/finance')->assertForbidden();
});
