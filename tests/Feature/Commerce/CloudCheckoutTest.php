<?php

use App\Models\Checkout;

it('starts a checkout for the cloud company the cloud instance acts for', function () {
    [, , $listing] = sellableListing(4900);
    $instance = cloudPlatformInstance(['host' => 'https://api.fleetbase.test']);
    actingAsInstance($instance);
    $acme = cloudCompany('Acme');

    actingForCompany($acme);
    $handoff = $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.fleetbase.test/extensions/purchased'])
        ->assertCreated()
        ->assertJsonPath('organization.id', $acme->public_id)
        ->json('handoff');

    expect(Checkout::sole()->organization_uuid)->toBe($acme->uuid)
        ->and(stripe()->sent('post', '/v1/checkout/sessions')[0]['params']['metadata']['organization_uuid'])->toBe($acme->uuid);

    $this->getJson("/v1/instances/me/purchases/{$handoff}")->assertOk();

    // Another company cannot read Acme's hand-off.
    actingForCompany(cloudCompany('Globex'));
    $this->getJson("/v1/instances/me/purchases/{$handoff}")->assertNotFound();
});
