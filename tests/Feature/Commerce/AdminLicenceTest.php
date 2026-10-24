<?php

use App\Models\Checkout;
use App\Models\Event;
use App\Models\Licence;
use Illuminate\Support\Facades\Mail;

function staffManagedLicence(int $seats = 5): Licence
{
    [, , $listing] = sellableListing();
    [, $organization] = buyerOrganization();

    return Licence::create(['extension_uuid' => $listing->uuid, 'organization_uuid' => $organization->uuid, 'kind' => Licence::KIND_PAID, 'max_activations' => $seats, 'status' => Licence::STATUS_ACTIVE]);
}

it('lets staff find, revoke and reinstate a licence', function () {
    $licence = staffManagedLicence();
    $staff = staffAccount();
    actingAsAccount($staff);

    $this->getJson('/v1/admin/licences?organization='.$licence->organization->slug)
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $licence->public_id)
        ->assertJsonPath('0.organization.slug', $licence->organization->slug);

    $this->postJson("/v1/admin/licences/{$licence->public_id}/revoke", [])->assertStatus(422);
    $this->postJson("/v1/admin/licences/{$licence->public_id}/revoke", ['reason' => 'Chargeback abuse'])
        ->assertOk()
        ->assertJsonPath('status', 'revoked')
        ->assertJsonPath('revoked_reason', 'staff');
    $this->postJson("/v1/admin/licences/{$licence->public_id}/revoke", ['reason' => 'again'])->assertStatus(409);
    expect($licence->refresh()->isActive())->toBeFalse()
        ->and($licence->meta['revoked_note'])->toBe('Chargeback abuse');

    $this->postJson("/v1/admin/licences/{$licence->public_id}/reinstate", ['reason' => 'Dispute won'])
        ->assertOk()
        ->assertJsonPath('status', 'active');
    expect($licence->refresh()->isActive())->toBeTrue()
        ->and(Event::whereIn('kind', ['licence.revoked', 'licence.reinstated'])->orderBy('id')->pluck('kind')->all())->toBe(['licence.revoked', 'licence.reinstated']);
});

it('reinstates a licence revoked by a dispute', function () {
    Mail::fake();
    [, , $listing] = sellableListing();
    [, $organization] = buyerOrganization();
    actingAsInstance(buyerInstance($organization));
    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x'])->assertCreated();
    $session = Checkout::sole()->stripe_checkout_session_id;
    stripeWebhook('checkout.session.completed', stripeFixture('checkout_session', ['id' => $session]))->assertOk();
    stripeWebhook('charge.dispute.created', stripeFixture('dispute'))->assertOk();
    $licence = Licence::sole();
    expect($licence->status)->toBe(Licence::STATUS_REVOKED);

    actingAsAccount(staffAccount());
    $this->postJson("/v1/admin/licences/{$licence->public_id}/reinstate")->assertOk()->assertJsonPath('status', 'active');
    expect(Event::where('kind', 'licence.reinstated')->sole()->payload['was'])->toBe('disputed');
});

it('lets staff change seats, never below the seats in use', function () {
    $licence = staffManagedLicence();
    $licence->activations()->create(['instance_uuid' => buyerInstance($licence->organization)->uuid, 'activated_at' => now()]);
    $licence->activations()->create(['instance_uuid' => buyerInstance($licence->organization)->uuid, 'activated_at' => now()]);
    actingAsAccount(staffAccount());

    $this->patchJson("/v1/admin/licences/{$licence->public_id}", ['max_activations' => 1])->assertStatus(422);
    $this->patchJson("/v1/admin/licences/{$licence->public_id}", ['max_activations' => 10])->assertOk()->assertJsonPath('max_activations', 10);

    expect(Event::where('kind', 'licence.seats_changed')->sole()->payload)->toBe(['from' => 5, 'to' => 10]);
});

it('keeps licence management to staff', function () {
    $licence = staffManagedLicence();
    [$account] = buyerOrganization();
    actingAsAccount($account);

    $this->getJson('/v1/admin/licences')->assertForbidden();
    $this->postJson("/v1/admin/licences/{$licence->public_id}/revoke", ['reason' => 'x'])->assertForbidden();
});
