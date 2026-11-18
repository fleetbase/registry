<?php

use App\Models\Account;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Membership;
use App\Models\Token;

/*
 * Access grants through the publisher API (owners and admins of the listing's organization)
 * and the staff API.
 */

function extensionOwner($extension, string $role = Membership::ROLE_OWNER): Account
{
    $account = Account::factory()->create();
    $account->organizations()->attach($extension->organization_uuid, ['role' => $role]);

    return $account;
}

beforeEach(function () {
    $this->extension = privateExtension();
    $this->owner = extensionOwner($this->extension);
    [$this->client, $this->instance] = clientOrganization();
});

it('grants an organization access, lists it, and the grant opens the packages', function () {
    actingAsAccount($this->owner, [Token::SCOPE_PUBLISH]);

    $grant = $this->postJson('/v1/publisher/extensions/client-secret/access', [
        'organization' => $this->client->slug,
        'note' => 'Contract #42',
    ])->assertCreated()
        ->assertJsonPath('organization.slug', $this->client->slug)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('instances', [])
        ->assertJsonPath('max_activations', null)
        ->assertJsonPath('note', 'Contract #42')
        ->json();

    $this->getJson('/v1/publisher/extensions/client-secret/access')->assertOk()
        ->assertJsonPath('visibility', 'private')
        ->assertJsonPath('pending_visibility', null)
        ->assertJsonPath('grants.0.id', $grant['id']);

    expect(Licence::query()->where('public_id', $grant['id'])->value('kind'))->toBe(Licence::KIND_GRANT);
    $this->get(PRIVATE_ROUTES['npm packument'], instanceHeaders($this->instance))->assertOk();
});

it('limits a grant to instances, validates them, and narrows seats on update', function () {
    actingAsAccount($this->owner, [Token::SCOPE_PUBLISH]);
    $second = Instance::factory()->for($this->client)->create();
    [, $foreign] = clientOrganization('Elsewhere');

    $this->postJson('/v1/publisher/extensions/client-secret/access', ['organization' => $this->client->public_id, 'instances' => [$foreign->public_id]])
        ->assertUnprocessable()->assertJsonValidationErrors('instances.0', 'fields');

    $grant = $this->postJson('/v1/publisher/extensions/client-secret/access', [
        'organization' => $this->client->public_id,
        'instances' => [$this->instance->public_id, $second->public_id],
        'max_activations' => 2,
    ])->assertCreated()->assertJsonCount(2, 'instances')->json();

    $licence = Licence::query()->where('public_id', $grant['id'])->firstOrFail();
    foreach ([$this->instance, $second] as $instance) {
        LicenceActivation::create(['licence_uuid' => $licence->uuid, 'instance_uuid' => $instance->uuid, 'activated_at' => now()]);
    }

    $this->patchJson("/v1/publisher/extensions/client-secret/access/{$grant['id']}", ['max_activations' => 1])
        ->assertUnprocessable()->assertJsonValidationErrors('max_activations', 'fields');

    $this->patchJson("/v1/publisher/extensions/client-secret/access/{$grant['id']}", ['instances' => [$this->instance->public_id]])
        ->assertOk()->assertJsonPath('instances.0.id', $this->instance->public_id)->assertJsonPath('seats_used', 1);

    expect(LicenceActivation::query()->where('instance_uuid', $second->uuid)->whereNull('deactivated_at')->exists())->toBeFalse();
    $this->get(PRIVATE_ROUTES['composer p2'], instanceHeaders($second))->assertNotFound();
    $this->get(PRIVATE_ROUTES['composer p2'], instanceHeaders($this->instance))->assertOk();

    // back to every instance
    $this->patchJson("/v1/publisher/extensions/client-secret/access/{$grant['id']}", ['instances' => null])->assertOk()->assertJsonPath('instances', []);
    $this->get(PRIVATE_ROUTES['composer p2'], instanceHeaders($second))->assertOk();
});

it('revokes a grant: downloads stop at once and seats are released', function () {
    actingAsAccount($this->owner, [Token::SCOPE_PUBLISH]);
    $grant = accessGrant($this->extension, $this->client);
    LicenceActivation::create(['licence_uuid' => $grant->uuid, 'instance_uuid' => $this->instance->uuid, 'activated_at' => now()]);
    $headers = instanceHeaders($this->instance);
    $this->get(PRIVATE_ROUTES['npm tarball'], $headers)->assertOk();

    $this->deleteJson("/v1/publisher/extensions/client-secret/access/{$grant->public_id}", ['reason' => 'Contract ended'])
        ->assertOk()->assertJsonPath('status', 'revoked')->assertJsonPath('seats_used', 0);

    $this->get(PRIVATE_ROUTES['npm tarball'], $headers)->assertNotFound();
    $this->getJson('/v1/catalog/extensions/client-secret', $headers)->assertNotFound();
    $this->deleteJson("/v1/publisher/extensions/client-secret/access/{$grant->public_id}")->assertConflict();
    $this->patchJson("/v1/publisher/extensions/client-secret/access/{$grant->public_id}", ['note' => 'x'])->assertConflict();

    // granting again works
    $this->postJson('/v1/publisher/extensions/client-secret/access', ['organization' => $this->client->slug])->assertCreated();
    $this->get(PRIVATE_ROUTES['npm tarball'], instanceHeaders($this->instance))->assertOk();
});

it('refuses duplicate grants, grants to the owner, and unknown organizations', function () {
    actingAsAccount($this->owner, [Token::SCOPE_PUBLISH]);
    accessGrant($this->extension, $this->client);

    $this->postJson('/v1/publisher/extensions/client-secret/access', ['organization' => $this->client->slug])->assertConflict();
    $this->postJson('/v1/publisher/extensions/client-secret/access', ['organization' => $this->extension->organization->slug])
        ->assertUnprocessable()->assertJsonValidationErrors('organization', 'fields');
    $this->postJson('/v1/publisher/extensions/client-secret/access', ['organization' => 'no-such-org'])
        ->assertUnprocessable()->assertJsonValidationErrors('organization', 'fields');
    $this->postJson('/v1/publisher/extensions/client-secret/access', ['organization' => $this->client->slug, 'expires_at' => now()->subDay()->toIso8601String()])
        ->assertUnprocessable()->assertJsonValidationErrors('expires_at', 'fields');
});

it('lets only owners and admins manage access', function () {
    actingAsAccount(extensionOwner($this->extension, Membership::ROLE_ADMIN), [Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/publisher/extensions/client-secret/access')->assertOk();

    actingAsAccount(extensionOwner($this->extension, Membership::ROLE_PUBLISHER), [Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/publisher/extensions/client-secret/access')->assertForbidden();
    $this->postJson('/v1/publisher/extensions/client-secret/access', ['organization' => $this->client->slug])->assertForbidden();

    actingAsAccount(Account::factory()->create(), [Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/publisher/extensions/client-secret/access')->assertNotFound();
});

it('lets staff manage any listing\'s grants through the admin API', function () {
    actingAsAccount(staffAccount(), [Token::SCOPE_REVIEW]);

    $grant = $this->postJson('/v1/admin/extensions/client-secret/access', ['organization' => $this->client->slug, 'instances' => [$this->instance->public_id]])
        ->assertCreated()->json('id');
    $this->getJson('/v1/admin/extensions/client-secret/access')->assertOk()->assertJsonPath('grants.0.id', $grant);
    $this->deleteJson("/v1/admin/extensions/client-secret/access/{$grant}")->assertOk()->assertJsonPath('status', 'revoked');

    actingAsAccount($this->owner, [Token::SCOPE_REVIEW]);
    $this->getJson('/v1/admin/extensions/client-secret/access')->assertForbidden();
});

it('looks up an organization to grant to, listing instances only for staff and members', function () {
    actingAsAccount($this->owner, [Token::SCOPE_PUBLISH]);
    $this->getJson("/v1/publisher/access/organizations/{$this->client->slug}")->assertOk()
        ->assertJsonPath('slug', $this->client->slug)
        ->assertJsonPath('instances', null);
    $this->getJson('/v1/publisher/access/organizations/nobody-here')->assertNotFound();

    $staff = staffAccount();
    actingAsAccount($staff, [Token::SCOPE_ALL]);
    $this->getJson("/v1/publisher/access/organizations/{$this->client->slug}")->assertOk()
        ->assertJsonPath('instances.0.id', $this->instance->public_id);
});
