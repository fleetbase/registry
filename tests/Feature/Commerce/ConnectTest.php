<?php

use App\Commerce\ConnectAccounts;
use App\Models\Account;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;

it('creates one Express account per organization with the console controller settings', function () {
    [$account, $organization] = buyerOrganization();
    actingAsAccount($account);

    $this->postJson("/v1/organizations/{$organization->slug}/stripe/account")
        ->assertCreated()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('payout_status', Organization::PAYOUT_PENDING)
        ->assertJsonPath('can_receive_payments', false)
        ->assertJsonPath('requirements_due', ['external_account', 'tos_acceptance.date', 'tos_acceptance.ip', 'business_profile.mcc']);

    $sent = stripe()->sent('post', '/v1/accounts');
    expect($sent)->toHaveCount(1)
        ->and($sent[0]['params']['controller'])->toBe([
            'stripe_dashboard' => ['type' => 'express'],
            'fees' => ['payer' => 'application'],
            'losses' => ['payments' => 'application'],
        ])
        ->and($sent[0]['params']['capabilities'])->toBe(['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']])
        ->and($sent[0]['params']['email'])->toBe($account->email)
        ->and($sent[0]['params']['metadata'])->toBe(['organization' => $organization->public_id, 'organization_uuid' => $organization->uuid])
        ->and(FakeStripeHttpClient::header($sent[0], 'Idempotency-Key'))->toBe('connect-account:'.$organization->uuid);

    $organization->refresh();
    expect($organization->stripe_connect_id)->toStartWith('acct_')
        ->and(Event::where('kind', 'organization.stripe_account_created')->count())->toBe(1);

    // A second call keeps the account.
    $this->postJson("/v1/organizations/{$organization->slug}/stripe/account")->assertOk();
    expect(stripe()->sent('post', '/v1/accounts'))->toHaveCount(1);
});

it('opens an Account Session with the embedded components for owners and admins', function () {
    [$account, $organization] = buyerOrganization(Membership::ROLE_ADMIN);
    $organization->forceFill(['stripe_connect_id' => 'acct_1QfLmTPublisher01'])->save();
    actingAsAccount($account);

    $this->postJson("/v1/organizations/{$organization->slug}/stripe/account-session")
        ->assertOk()
        ->assertJsonPath('client_secret', 'accs_secret__QfLmTdYxGvZ1kRk6S5bdlFJxXyZ')
        ->assertJsonPath('publishable_key', 'pk_test_registry')
        ->assertJsonPath('components', ['account_onboarding', 'account_management', 'notification_banner', 'payouts', 'tax_registrations', 'tax_settings']);

    $params = stripe()->sent('post', '/v1/account_sessions')[0]['params'];
    expect($params['account'])->toBe('acct_1QfLmTPublisher01')
        ->and($params['components']['account_onboarding'])->toBe(['enabled' => 'true', 'features' => ['external_account_collection' => 'true']])
        ->and($params['components']['payouts'])->toBe(['enabled' => 'true']);
});

it('keeps payouts setup to owners and admins and to members of the organization', function () {
    [$account, $organization] = buyerOrganization(Membership::ROLE_PUBLISHER);
    actingAsAccount($account);

    $this->postJson("/v1/organizations/{$organization->slug}/stripe/account")
        ->assertForbidden()
        ->assertExactJson(['errors' => ['Only organization owners and admins can set up payouts.']]);
    $this->postJson("/v1/organizations/{$organization->slug}/stripe/account-session")->assertForbidden();

    // Members still see the status.
    $this->getJson("/v1/organizations/{$organization->slug}/stripe")->assertOk()->assertJsonPath('connected', false);

    $other = Organization::factory()->create();
    $this->getJson("/v1/organizations/{$other->slug}/stripe")->assertNotFound();

    expect(stripe()->requests)->toBe([]);
});

it('asks for the account before opening a session', function () {
    [$account, $organization] = buyerOrganization();
    actingAsAccount($account);

    $this->postJson("/v1/organizations/{$organization->slug}/stripe/account-session")
        ->assertStatus(422)
        ->assertExactJson(['errors' => ['Create the Stripe account first.']]);
});

it('refreshes the account from Stripe on request', function () {
    [$account, $organization] = buyerOrganization();
    $organization->forceFill(['stripe_connect_id' => 'acct_1QfLmTPublisher01', 'payout_status' => Organization::PAYOUT_PENDING])->save();
    actingAsAccount($account);

    $this->getJson("/v1/organizations/{$organization->slug}/stripe?refresh=1")
        ->assertOk()
        ->assertJsonPath('payout_status', Organization::PAYOUT_ENABLED)
        ->assertJsonPath('can_receive_payments', true)
        ->assertJsonPath('country', 'US')
        ->assertJsonPath('default_currency', 'USD');

    expect(Event::where('kind', 'organization.payout_status_changed')->sole()->payload)->toBe(['from' => 'pending', 'to' => 'enabled', 'account' => 'acct_1QfLmTPublisher01']);
});

it('answers 503 for commerce routes until Stripe is configured', function () {
    config(['services.stripe.secret' => null]);
    [$account, $organization] = buyerOrganization();
    actingAsAccount($account);

    $this->postJson("/v1/organizations/{$organization->slug}/stripe/account")
        ->assertStatus(503)
        ->assertExactJson(['errors' => ['Payments are not set up on this registry.']]);
});

it('reports Stripe errors as a bad gateway', function () {
    [$account, $organization] = buyerOrganization();
    actingAsAccount($account);
    stripe()->on('post', '/v1/accounts', stripeFixture('error_invalid_request'), 400);

    $this->postJson("/v1/organizations/{$organization->slug}/stripe/account")
        ->assertStatus(502)
        ->assertJsonPath('errors.0', fn ($message) => str_starts_with($message, 'Stripe could not complete the request: You passed an empty string'));

    expect($organization->refresh()->stripe_connect_id)->toBeNull();
});

it('derives payout status from the account', function (array $overrides, string $status) {
    $account = Stripe\Account::constructFrom(stripeFixture('account', $overrides));

    expect(ConnectAccounts::payoutStatus($account))->toBe($status);
})->with([
    'enabled' => [[], Organization::PAYOUT_ENABLED],
    'details missing' => [['details_submitted' => false, 'charges_enabled' => false, 'payouts_enabled' => false], Organization::PAYOUT_PENDING],
    'payouts paused' => [['payouts_enabled' => false, 'requirements' => ['disabled_reason' => 'requirements.past_due']], Organization::PAYOUT_RESTRICTED],
    'rejected' => [['charges_enabled' => false, 'requirements' => ['disabled_reason' => 'rejected.fraud']], Organization::PAYOUT_REJECTED],
]);

it('lets a website session set up payouts', function () {
    $account = Account::factory()->create();
    $organization = Organization::factory()->create();
    $account->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_OWNER]);
    config(['session.driver' => 'database']);
    $cookies = websiteSignIn($account);

    websiteRequest('POST', "/v1/organizations/{$organization->slug}/stripe/account", $cookies)->assertCreated();
    websiteRequest('POST', "/v1/organizations/{$organization->slug}/stripe/account-session", $cookies, [], csrf: false)->assertStatus(419);
});
