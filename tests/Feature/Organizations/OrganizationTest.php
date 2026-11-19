<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Token;

/**
 * An organization with an owner and, per role, one more member.
 *
 * @return array{0: Organization, 1: array<string, Account>}
 */
function team(array $roles = [Membership::ROLE_ADMIN, Membership::ROLE_PUBLISHER, Membership::ROLE_MEMBER]): array
{
    $organization = Organization::factory()->create(['slug' => 'acme']);
    $accounts = ['owner' => Account::factory()->create(['name' => 'Olivia Owner'])];
    foreach ($roles as $role) {
        $accounts[$role] = Account::factory()->create();
    }
    foreach ($accounts as $role => $account) {
        $account->organizations()->attach($organization->uuid, ['role' => $role]);
    }

    return [$organization, $accounts];
}

it('lists the caller\'s organizations with role and member count', function () {
    [$organization, $accounts] = team();
    $personal = Organization::factory()->personal()->create();
    $accounts['admin']->organizations()->attach($personal->uuid, ['role' => Membership::ROLE_OWNER]);
    Organization::factory()->create();
    actingAsAccount($accounts['admin']);

    $this->getJson('/v1/organizations')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.slug', $personal->slug)
        ->assertJsonPath('0.role', 'owner')
        ->assertJsonPath('0.members_count', 1)
        ->assertJsonPath('1.slug', 'acme')
        ->assertJsonPath('1.role', 'admin')
        ->assertJsonPath('1.members_count', 4);
});

it('creates an organization with the caller as owner', function () {
    $account = Account::factory()->create();
    Organization::factory()->create(['slug' => 'taken']);
    actingAsAccount($account);

    $this->postJson('/v1/organizations', ['name' => 'Globex Corp', 'website' => 'https://globex.example.com', 'bio' => 'Hi'])
        ->assertCreated()
        ->assertJsonPath('slug', 'globex-corp')
        ->assertJsonPath('role', 'owner')
        ->assertJsonPath('members.0.id', $account->public_id)
        ->assertJsonPath('members.0.role', 'owner')
        ->assertJsonPath('is_personal', false);

    $this->postJson('/v1/organizations', ['name' => 'Initech', 'slug' => 'initech'])->assertCreated()->assertJsonPath('slug', 'initech');
    $this->postJson('/v1/organizations', ['name' => 'X', 'slug' => 'taken'])->assertStatus(422);
    $this->postJson('/v1/organizations', ['name' => 'Fleetbase', 'slug' => 'fleetbase'])->assertStatus(422)->assertJsonPath('fields.slug.0', 'That slug is reserved.');
    $this->postJson('/v1/organizations', ['name' => 'Bad', 'slug' => 'Bad_Slug'])->assertStatus(422);
    $this->postJson('/v1/organizations', ['name' => 'Fleetbase'])->assertCreated()->assertJsonPath('slug', fn ($slug) => str_starts_with($slug, 'fleetbase-'));

    expect($account->roleIn(Organization::where('slug', 'initech')->sole()))->toBe('owner')
        ->and(Event::where('kind', 'organization.created')->count())->toBe(3);
});

it('shows an organization with its members to members only', function () {
    [$organization, $accounts] = team();
    actingAsAccount($accounts['member']);

    $this->getJson('/v1/organizations/acme')
        ->assertOk()
        ->assertJsonPath('id', $organization->public_id)
        ->assertJsonPath('role', 'member')
        ->assertJsonPath('members_count', 4)
        ->assertJsonPath('members.0.role', 'owner')
        ->assertJsonPath('members.0.name', 'Olivia Owner')
        ->assertJsonPath('members.0.email', $accounts['owner']->email)
        ->assertJsonPath('members.0.username', $accounts['owner']->username)
        ->assertJsonStructure(['members' => [['id', 'name', 'username', 'email', 'role', 'joined_at']]]);
    $this->getJson('/v1/organizations/'.$organization->public_id)->assertOk();

    actingAsAccount(Account::factory()->create());
    $this->getJson('/v1/organizations/acme')->assertNotFound();
});

it('lets owners and admins edit the profile but never the slug', function () {
    [, $accounts] = team();

    actingAsAccount($accounts['publisher']);
    $this->patchJson('/v1/organizations/acme', ['name' => 'Nope'])->assertForbidden();

    actingAsAccount($accounts['admin']);
    $this->patchJson('/v1/organizations/acme', ['name' => 'Acme Inc', 'bio' => 'We make anvils.', 'website' => 'https://acme.example.com', 'avatar_url' => 'https://cdn.example.com/a.png'])
        ->assertOk()
        ->assertJsonPath('name', 'Acme Inc')
        ->assertJsonPath('bio', 'We make anvils.')
        ->assertJsonPath('avatar_url', 'https://cdn.example.com/a.png');
    $this->patchJson('/v1/organizations/acme', ['slug' => 'other'])->assertStatus(422)->assertJsonPath('fields.slug.0', 'An organization\'s slug cannot be changed.');
    $this->patchJson('/v1/organizations/acme', ['website' => 'javascript:alert(1)'])->assertStatus(422);

    // Personal organizations can be edited by their owner.
    $personal = Organization::factory()->personal()->create();
    $accounts['admin']->organizations()->attach($personal->uuid, ['role' => Membership::ROLE_OWNER]);
    $this->patchJson('/v1/organizations/'.$personal->slug, ['name' => 'Me'])->assertOk()->assertJsonPath('name', 'Me');
});

it('adds existing accounts as members', function () {
    [$organization, $accounts] = team();
    $newcomer = Account::factory()->create(['email' => 'new@example.com']);

    actingAsAccount($accounts['publisher']);
    $this->postJson('/v1/organizations/acme/members', ['email' => 'new@example.com', 'role' => 'member'])->assertForbidden();

    actingAsAccount($accounts['admin']);
    $this->postJson('/v1/organizations/acme/members', ['email' => 'nobody@example.com', 'role' => 'member'])
        ->assertStatus(422)
        ->assertExactJson(['errors' => ['No registry account uses that email. Ask them to register first.']]);
    $this->postJson('/v1/organizations/acme/members', ['email' => 'NEW@example.com', 'role' => 'owner'])
        ->assertForbidden()
        ->assertJsonPath('errors.0', 'Only owners can grant or change the owner role.');
    $this->postJson('/v1/organizations/acme/members', ['email' => 'new@example.com', 'role' => 'wizard'])->assertStatus(422);

    $this->postJson('/v1/organizations/acme/members', ['email' => 'NEW@example.com', 'role' => 'publisher'])
        ->assertCreated()
        ->assertJsonPath('id', $newcomer->public_id)
        ->assertJsonPath('role', 'publisher');
    $this->postJson('/v1/organizations/acme/members', ['email' => 'new@example.com', 'role' => 'member'])->assertStatus(409);

    expect($newcomer->roleIn($organization))->toBe('publisher')
        ->and(Event::where('kind', 'organization.member_added')->sole()->payload)->toBe(['account' => $newcomer->public_id, 'role' => 'publisher']);

    // Personal organizations take no members.
    $personal = Organization::factory()->personal()->create();
    $accounts['admin']->organizations()->attach($personal->uuid, ['role' => Membership::ROLE_OWNER]);
    $this->postJson('/v1/organizations/'.$personal->slug.'/members', ['email' => 'new@example.com', 'role' => 'member'])->assertStatus(422);
});

it('changes roles, with the owner role reserved to owners', function () {
    [$organization, $accounts] = team();

    actingAsAccount($accounts['admin']);
    $this->patchJson('/v1/organizations/acme/members/'.$accounts['member']->public_id, ['role' => 'publisher'])
        ->assertOk()
        ->assertJsonPath('role', 'publisher');
    $this->patchJson('/v1/organizations/acme/members/'.$accounts['member']->public_id, ['role' => 'owner'])->assertForbidden();
    $this->patchJson('/v1/organizations/acme/members/'.$accounts['owner']->public_id, ['role' => 'member'])->assertForbidden();
    $this->patchJson('/v1/organizations/acme/members/acct_missing', ['role' => 'member'])->assertNotFound();

    actingAsAccount($accounts['owner']);
    $this->patchJson('/v1/organizations/acme/members/'.$accounts['admin']->username, ['role' => 'owner'])->assertOk()->assertJsonPath('role', 'owner');

    expect($accounts['member']->roleIn($organization))->toBe('publisher')
        ->and(Event::where('kind', 'organization.member_role_changed')->count())->toBe(2);
});

it('never leaves an organization without an owner', function () {
    [$organization, $accounts] = team([Membership::ROLE_ADMIN]);

    actingAsAccount($accounts['owner']);
    $this->patchJson('/v1/organizations/acme/members/'.$accounts['owner']->public_id, ['role' => 'admin'])
        ->assertStatus(422)
        ->assertExactJson(['errors' => ['The last owner cannot be demoted. Make another member an owner first.']]);
    $this->deleteJson('/v1/organizations/acme/members/'.$accounts['owner']->public_id)
        ->assertStatus(422)
        ->assertExactJson(['errors' => ['The last owner cannot be removed. Make another member an owner first.']]);

    // With a second owner, the first may step down.
    $this->patchJson('/v1/organizations/acme/members/'.$accounts['admin']->public_id, ['role' => 'owner'])->assertOk();
    $this->patchJson('/v1/organizations/acme/members/'.$accounts['owner']->public_id, ['role' => 'admin'])->assertOk();

    expect(Membership::where('organization_uuid', $organization->uuid)->where('role', 'owner')->pluck('account_uuid')->all())->toBe([$accounts['admin']->uuid]);
});

it('removes members: managers remove others, anyone can leave', function () {
    [$organization, $accounts] = team([Membership::ROLE_ADMIN, Membership::ROLE_PUBLISHER, Membership::ROLE_MEMBER, Membership::ROLE_BILLING]);

    actingAsAccount($accounts['publisher']);
    $this->deleteJson('/v1/organizations/acme/members/'.$accounts['member']->public_id)->assertForbidden();
    $this->deleteJson('/v1/organizations/acme/members/'.$accounts['publisher']->public_id)->assertOk()->assertJsonPath('removed', true);

    actingAsAccount($accounts['admin']);
    $this->deleteJson('/v1/organizations/acme/members/'.$accounts['owner']->public_id)->assertForbidden();
    $this->deleteJson('/v1/organizations/acme/members/'.$accounts['member']->public_id)->assertOk();

    expect($organization->memberships()->pluck('role')->sort()->values()->all())->toBe(['admin', 'billing', 'owner'])
        ->and(Event::where('kind', 'organization.member_left')->count())->toBe(1)
        ->and(Event::where('kind', 'organization.member_removed')->count())->toBe(1);

    // A removed member no longer sees the organization.
    actingAsAccount($accounts['member']);
    $this->getJson('/v1/organizations/acme')->assertNotFound();
});

it('keeps tokens pinned to an organization inside it', function () {
    [, $accounts] = team();
    $other = Organization::factory()->create();
    $accounts['owner']->organizations()->attach($other->uuid, ['role' => Membership::ROLE_OWNER]);
    $token = Token::issue($accounts['owner'], 'pinned', ['*'], null, $other->uuid);
    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);

    $this->getJson('/v1/organizations')->assertOk()->assertJsonCount(1)->assertJsonPath('0.slug', $other->slug);
    $this->getJson('/v1/organizations/acme')->assertForbidden();
    $this->postJson('/v1/organizations', ['name' => 'New'])->assertForbidden();
});

it('works over the website session', function () {
    [, $accounts] = team();
    $accounts['owner']->forceFill(['password' => 'password'])->save();
    $cookies = websiteSignIn($accounts['owner']);

    websiteRequest('GET', '/v1/organizations', $cookies)->assertOk()->assertJsonPath('0.slug', 'acme');
    websiteRequest('PATCH', '/v1/organizations/acme', $cookies, ['bio' => 'Updated'], csrf: false)->assertStatus(419);
    websiteRequest('PATCH', '/v1/organizations/acme', $cookies, ['bio' => 'Updated'])->assertOk()->assertJsonPath('bio', 'Updated');
});
