<?php

use App\Models\Account;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Token;

/*
 * Private extensions over npm and Composer: 404 (never 401 or 403) for every caller without
 * access, 200 for the owner and organizations holding a grant or paid licence that covers the
 * caller, private cache headers throughout.
 */

it('answers 404 to anonymous callers on every package route', function (string $uri) {
    privateExtension();

    $this->get($uri)->assertNotFound()
        ->assertHeaderMissing('WWW-Authenticate')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('error', 'Package not found.');
})->with(PRIVATE_ROUTES);

it('answers 404, not 401 or 403, to callers without access, even for paid private extensions', function (string $uri, bool $paid) {
    $extension = privateExtension(paid: $paid);
    [$stranger, $strangerInstance, $strangerAccount] = clientOrganization('Stranger');

    // invalid token, foreign instance, foreign personal token, token without the install scope
    $this->get($uri, ['Authorization' => 'Bearer flbp_not-a-token'])->assertNotFound();
    $this->get($uri, ['Authorization' => 'Bearer flbi_not-a-token'])->assertNotFound();
    $this->get($uri, instanceHeaders($strangerInstance))->assertNotFound();
    $this->get($uri, personalHeaders($strangerAccount))->assertNotFound();

    $owner = Account::factory()->create();
    $owner->organizations()->attach($extension->organization_uuid, ['role' => Membership::ROLE_OWNER]);
    $this->get($uri, personalHeaders($owner, [Token::SCOPE_READ]))->assertNotFound();
})->with(PRIVATE_ROUTES)->with(['free' => false, 'paid' => true]);

it('serves the owner organization, privately', function (string $uri) {
    $extension = privateExtension();
    $instance = Instance::factory()->create(['organization_uuid' => $extension->organization_uuid]);

    $response = $this->get($uri, instanceHeaders($instance))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');
    expect($response->headers->get('Vary'))->toContain('Authorization');
})->with(PRIVATE_ROUTES);

it('serves an organization holding a grant, to its instances and install tokens', function (string $uri) {
    $extension = privateExtension();
    [$client, $instance, $account] = clientOrganization();
    accessGrant($extension, $client);

    $this->get($uri, instanceHeaders($instance))->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $this->get($uri, personalHeaders($account))->assertOk();
})->with(PRIVATE_ROUTES);

it('limits an instance-limited grant to the listed instances', function () {
    $extension = privateExtension();
    [$client, $allowed, $account] = clientOrganization();
    $other = Instance::factory()->for($client)->create();
    accessGrant($extension, $client, ['instance_uuids' => [$allowed->uuid]]);

    foreach (PRIVATE_ROUTES as $uri) {
        $this->get($uri, instanceHeaders($allowed))->assertOk();
        $this->get($uri, instanceHeaders($other))->assertNotFound();
        // personal tokens of the organization are not instances on the list
        $this->get($uri, personalHeaders($account))->assertNotFound();
    }
});

it('stops serving the moment a grant is revoked or expires', function () {
    $extension = privateExtension();
    [$client, $instance] = clientOrganization();
    $grant = accessGrant($extension, $client);
    $headers = instanceHeaders($instance);

    $this->get(PRIVATE_ROUTES['npm packument'], $headers)->assertOk();
    $this->get(PRIVATE_ROUTES['composer dist'], $headers)->assertOk();

    $grant->forceFill(['status' => Licence::STATUS_REVOKED])->save();
    foreach (PRIVATE_ROUTES as $uri) {
        $this->get($uri, $headers)->assertNotFound();
    }

    $grant->forceFill(['status' => Licence::STATUS_ACTIVE, 'expires_at' => now()->subMinute()])->save();
    $this->get(PRIVATE_ROUTES['npm tarball'], $headers)->assertNotFound();
});

it('never opens a private extension through an automatic free licence', function () {
    $extension = privateExtension();
    [$client, $instance] = clientOrganization();
    accessGrant($extension, $client, ['kind' => Licence::KIND_FREE]);

    $this->get(PRIVATE_ROUTES['npm packument'], instanceHeaders($instance))->assertNotFound();

    // a purchased licence does open it
    accessGrant($extension, $client, ['kind' => Licence::KIND_PAID]);
    $this->get(PRIVATE_ROUTES['npm packument'], instanceHeaders($instance))->assertOk();
});

it('lists private Composer packages in packages.json only for callers with access', function () {
    $extension = privateExtension();
    makeRelease(makePackage('composer', 'acme/open-api'), '1.0.0');
    [$client, $instance] = clientOrganization();
    [, $strangerInstance] = clientOrganization('Stranger');

    $anonymous = $this->get('/composer/packages.json')->assertOk();
    expect($anonymous->json('available-packages'))->toBe(['acme/open-api']);
    expect($anonymous->headers->get('Cache-Control'))->toContain('public');
    expect($anonymous->headers->get('Vary'))->toContain('Authorization');

    expect($this->get('/composer/packages.json', instanceHeaders($strangerInstance))->json('available-packages'))->toBe(['acme/open-api']);

    accessGrant($extension, $client);
    $granted = $this->get('/composer/packages.json', instanceHeaders($instance))->assertOk();
    expect($granted->json('available-packages'))->toBe(['acme/open-api', 'client/secret-api']);
    expect($granted->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');
});

it('keeps paid public packages on 401 and 403 and lets a grant open them', function () {
    $npm = makePackage('npm', '@acme/pro-engine', paid: true);
    makeRelease($npm, '1.0.0');
    [$client, $instance, $account] = clientOrganization();

    $this->get('/npm/@acme%2fpro-engine')->assertUnauthorized();
    $this->get('/npm/@acme%2fpro-engine', personalHeaders($account))->assertForbidden();

    accessGrant($npm->extension, $client);
    $this->get('/npm/@acme%2fpro-engine', instanceHeaders($instance))->assertOk();
});
