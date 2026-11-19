<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\InstanceRegistrationCode;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Token;

function accountWithOrganization(string $role = Membership::ROLE_OWNER, bool $personal = true): array
{
    $account = Account::factory()->create();
    $organization = Organization::factory()->state(['is_personal' => $personal])->create();
    $account->organizations()->attach($organization->uuid, ['role' => $role]);

    return [$account, $organization];
}

/**
 * An instance of the organization holding a seat on a licence for an installed extension.
 *
 * @return array{0: Instance, 1: string, 2: Install, 3: LicenceActivation}
 */
function installedInstance(Organization $organization): array
{
    $instance = Instance::factory()->create(['organization_uuid' => $organization->uuid]);
    $token = $instance->issueToken();
    $extension = Extension::factory()->published()->create();
    $install = Install::factory()->create(['instance_uuid' => $instance->uuid, 'extension_uuid' => $extension->uuid]);
    $licence = Licence::create([
        'extension_uuid' => $extension->uuid,
        'organization_uuid' => $organization->uuid,
        'kind' => Licence::KIND_PAID,
        'max_activations' => 1,
        'status' => Licence::STATUS_ACTIVE,
    ]);
    $activation = LicenceActivation::create(['licence_uuid' => $licence->uuid, 'instance_uuid' => $instance->uuid, 'activated_at' => now()]);

    return [$instance, $token, $install, $activation];
}

function registrationPayload(string $code, array $overrides = []): array
{
    return array_merge([
        'code' => $code,
        'name' => 'Acme production',
        'host' => 'fleet.acme.test',
        'kind' => 'self_hosted',
        'console_version' => '0.7.2',
        'api_version' => '1.6.10',
    ], $overrides);
}

it('mints a 15-minute registration code for the personal organization', function () {
    [$account, $organization] = accountWithOrganization();
    actingAsAccount($account, [Token::SCOPE_INSTANCE_REGISTER]);

    $response = $this->postJson('/v1/account/instance-codes')->assertCreated()
        ->assertJsonPath('organization', $organization->public_id)
        ->assertJsonPath('expires_in', 900);

    $plain = $response->json('code');
    expect($plain)->toMatch('/^[0-9A-Z]{4}(-[0-9A-Z]{4}){3}$/');

    $code = InstanceRegistrationCode::first();
    expect($code->code_hash)->toBe(hash('sha256', $plain))
        ->and($code->code_hash)->not->toContain($plain)
        ->and($code->expires_at->diffInMinutes(now(), true))->toBeGreaterThan(14.9)->toBeLessThanOrEqual(15);
});

it('requires the instance:register scope and an owner or admin role', function () {
    [$account, $organization] = accountWithOrganization();

    actingAsAccount($account, [Token::SCOPE_READ]);
    $this->postJson('/v1/account/instance-codes')->assertStatus(403);

    $this->withHeader('Authorization', 'Bearer nope')->postJson('/v1/account/instance-codes')->assertStatus(401);

    [$member, $team] = accountWithOrganization(Membership::ROLE_PUBLISHER, false);
    actingAsAccount($member, [Token::SCOPE_INSTANCE_REGISTER]);
    $this->postJson('/v1/account/instance-codes', ['organization' => $team->slug])
        ->assertStatus(403)->assertJsonPath('errors.0', 'Only organization owners and admins can register instances.');

    $this->postJson('/v1/account/instance-codes', ['organization' => Organization::factory()->create()->slug])
        ->assertStatus(422);

    [$admin, $adminTeam] = accountWithOrganization(Membership::ROLE_ADMIN, false);
    actingAsAccount($admin, ['*']);
    $this->postJson('/v1/account/instance-codes', ['organization' => $adminTeam->public_id])
        ->assertCreated()->assertJsonPath('organization', $adminTeam->public_id);
});

it('defaults to and enforces the organization a token is bound to', function () {
    [$account, $personal] = accountWithOrganization();
    $team = Organization::factory()->create();
    $account->organizations()->attach($team->uuid, ['role' => Membership::ROLE_OWNER]);

    $token = Token::issue($account, 'team', [Token::SCOPE_INSTANCE_REGISTER], null, $team->uuid);
    $this->withHeader('Authorization', 'Bearer '.$token->plainTextToken);

    $this->postJson('/v1/account/instance-codes')->assertCreated()->assertJsonPath('organization', $team->public_id);
    $this->postJson('/v1/account/instance-codes', ['organization' => $personal->slug])->assertStatus(403);
});

it('registers an instance with a code and returns the instance token once', function () {
    [$account, $organization] = accountWithOrganization();
    $code = InstanceRegistrationCode::mint($organization, $account);

    $response = $this->postJson('/v1/instances/register', registrationPayload(strtolower($code->plainTextCode)))
        ->assertCreated()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('rotated', false)
        ->assertJsonPath('instance.name', 'Acme production')
        ->assertJsonPath('instance.kind', 'self_hosted')
        ->assertJsonPath('instance.console_version', '0.7.2')
        ->assertJsonPath('instance.organization.id', $organization->public_id);

    $plain = $response->json('token');
    expect($plain)->toStartWith('flbi_')->toHaveLength(45);

    $instance = Instance::first();
    expect($instance->token_hash)->toBe(hash('sha256', $plain))
        ->and($instance->registered_by_account_uuid)->toBe($account->uuid)
        ->and($instance->public_id)->toStartWith('inst_')
        ->and($code->fresh()->used_at)->not->toBeNull()
        ->and($code->fresh()->instance_uuid)->toBe($instance->uuid)
        ->and(json_encode($response->json('instance')))->not->toContain($plain);

    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/v1/instances/me')
        ->assertOk()->assertJsonPath('instance.id', $instance->public_id);
});

it('refuses used, expired and unknown codes', function () {
    [$account, $organization] = accountWithOrganization();
    $code = InstanceRegistrationCode::mint($organization, $account);

    $this->postJson('/v1/instances/register', registrationPayload($code->plainTextCode))->assertCreated();
    $this->postJson('/v1/instances/register', registrationPayload($code->plainTextCode))
        ->assertStatus(401)->assertJsonPath('errors.0', 'The registration code is invalid, expired or already used.');

    $expired = InstanceRegistrationCode::mint($organization, $account);
    $this->travel(16)->minutes();
    $this->postJson('/v1/instances/register', registrationPayload($expired->plainTextCode))->assertStatus(401);

    $this->postJson('/v1/instances/register', registrationPayload('AAAA-BBBB-CCCC-DDDD'))->assertStatus(401);
    expect(Instance::count())->toBe(1);
});

it('validates the registration body without burning the code', function () {
    [$account, $organization] = accountWithOrganization();
    $code = InstanceRegistrationCode::mint($organization, $account);

    $response = $this->postJson('/v1/instances/register', registrationPayload($code->plainTextCode, ['kind' => 'desktop', 'name' => '']))
        ->assertStatus(422);
    expect($response->json('fields'))->toHaveKeys(['kind', 'name']);
    expect($code->fresh()->used_at)->toBeNull();
});

it('keeps instance and personal tokens apart', function () {
    $account = Account::factory()->create();
    $personal = Token::issue($account, 'cli');
    $instance = Instance::factory()->create();
    $instanceToken = $instance->issueToken();

    $this->withHeader('Authorization', 'Bearer '.$personal->plainTextToken)->getJson('/v1/instances/me')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer '.$instanceToken)->getJson('/v1/account')->assertStatus(401);

    $instance->forceFill(['status' => Instance::STATUS_REVOKED])->save();
    $this->withHeader('Authorization', 'Bearer '.$instanceToken)->getJson('/v1/instances/me')->assertStatus(401);
});

it('rotates the same instance when it registers again with its previous token', function () {
    [$account, $organization] = accountWithOrganization();
    [$instance, $previousToken, $install, $activation] = installedInstance($organization);
    $code = InstanceRegistrationCode::mint($organization, $account);

    $response = $this->postJson('/v1/instances/register', registrationPayload($code->plainTextCode, [
        'previous_token' => $previousToken,
        'name' => 'Acme renamed',
        'console_version' => '0.7.3',
    ]))->assertOk()
        ->assertJsonPath('rotated', true)
        ->assertJsonPath('instance.id', $instance->public_id)
        ->assertJsonPath('instance.name', 'Acme renamed')
        ->assertJsonPath('instance.console_version', '0.7.3');

    $plain = $response->json('token');
    expect($plain)->toStartWith('flbi_')->not->toBe($previousToken)
        ->and(Instance::count())->toBe(1)
        ->and($instance->fresh()->token_hash)->toBe(hash('sha256', $plain))
        ->and($install->fresh()->status)->toBe(Install::STATUS_INSTALLED)
        ->and($activation->fresh()->deactivated_at)->toBeNull()
        ->and($code->fresh()->instance_uuid)->toBe($instance->uuid)
        ->and(Event::where('kind', 'instance.reregistered')->count())->toBe(1);

    $this->withHeader('Authorization', 'Bearer '.$previousToken)->getJson('/v1/instances/me')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer '.$plain)->getJson('/v1/instances/me')
        ->assertOk()->assertJsonPath('instance.id', $instance->public_id);
});

it('replaces an instance that registers into another organization', function () {
    [, $oldOrganization] = accountWithOrganization();
    [$instance, $previousToken, $install, $activation] = installedInstance($oldOrganization);
    [$account, $organization] = accountWithOrganization();
    $code = InstanceRegistrationCode::mint($organization, $account);

    $response = $this->postJson('/v1/instances/register', registrationPayload($code->plainTextCode, ['previous_token' => $previousToken]))
        ->assertCreated()
        ->assertJsonPath('instance.organization.id', $organization->public_id);

    $replacement = Instance::where('public_id', $response->json('instance.id'))->first();
    expect($replacement->uuid)->not->toBe($instance->uuid)
        ->and($instance->fresh()->status)->toBe(Instance::STATUS_REVOKED)
        ->and($activation->fresh()->deactivated_at)->not->toBeNull()
        ->and($install->fresh()->instance_uuid)->toBe($replacement->uuid)
        ->and($install->fresh()->status)->toBe(Install::STATUS_INSTALLED);

    $this->withHeader('Authorization', 'Bearer '.$previousToken)->getJson('/v1/instances/me')->assertStatus(401);
});

it('creates a new instance when the previous token is unknown or revoked', function () {
    [$account, $organization] = accountWithOrganization();
    [$instance, $previousToken] = installedInstance($organization);
    $instance->forceFill(['status' => Instance::STATUS_REVOKED])->save();

    $this->postJson('/v1/instances/register', registrationPayload(InstanceRegistrationCode::mint($organization, $account)->plainTextCode, ['previous_token' => $previousToken]))
        ->assertCreated();
    $this->postJson('/v1/instances/register', registrationPayload(InstanceRegistrationCode::mint($organization, $account)->plainTextCode, ['previous_token' => 'flbi_unknown', 'host' => 'staging.acme.test']))
        ->assertCreated();

    expect(Instance::count())->toBe(3)
        ->and($instance->fresh()->status)->toBe(Instance::STATUS_REVOKED);
});

it('rotates the active instance of the organization on the same host when no token is presented', function () {
    [$account, $organization] = accountWithOrganization();
    [$instance, $previousToken, $install, $activation] = installedInstance($organization);
    $instance->forceFill(['host' => 'https://Fleet.Acme.test/'])->save();

    $response = $this->postJson('/v1/instances/register', registrationPayload(InstanceRegistrationCode::mint($organization, $account)->plainTextCode, [
        'host' => 'fleet.acme.test',
    ]))->assertOk()
        ->assertJsonPath('rotated', true)
        ->assertJsonPath('instance.id', $instance->public_id)
        ->assertJsonPath('instance.host', 'fleet.acme.test');

    expect(Instance::count())->toBe(1)
        ->and($install->fresh()->instance_uuid)->toBe($instance->uuid)
        ->and($activation->fresh()->deactivated_at)->toBeNull()
        ->and(Event::where('kind', 'instance.reregistered')->first()->payload['matched_by'] ?? null)->toBe('host');

    $this->withHeader('Authorization', 'Bearer '.$previousToken)->getJson('/v1/instances/me')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer '.$response->json('token'))->getJson('/v1/instances/me')
        ->assertOk()->assertJsonPath('instance.id', $instance->public_id);
});

it('creates a new instance for a new host, or the same host in another organization', function () {
    [$account, $organization] = accountWithOrganization();
    [$instance, $previousToken] = installedInstance($organization);
    $instance->forceFill(['host' => 'fleet.acme.test'])->save();
    [$otherAccount, $otherOrganization] = accountWithOrganization();

    $this->postJson('/v1/instances/register', registrationPayload(InstanceRegistrationCode::mint($organization, $account)->plainTextCode, ['host' => 'eu.acme.test']))
        ->assertCreated()->assertJsonPath('rotated', false);
    $this->postJson('/v1/instances/register', registrationPayload(InstanceRegistrationCode::mint($otherOrganization, $otherAccount)->plainTextCode, ['host' => 'fleet.acme.test']))
        ->assertCreated()->assertJsonPath('instance.organization.id', $otherOrganization->public_id);

    expect(Instance::count())->toBe(3)
        ->and($instance->fresh()->status)->toBe(Instance::STATUS_ACTIVE)
        ->and($instance->fresh()->token_hash)->toBe(hash('sha256', $previousToken));
});

it('does not match a revoked instance or an empty host', function () {
    [$account, $organization] = accountWithOrganization();
    $revoked = Instance::factory()->create(['organization_uuid' => $organization->uuid, 'host' => 'fleet.acme.test', 'status' => Instance::STATUS_REVOKED]);
    Instance::factory()->create(['organization_uuid' => $organization->uuid, 'host' => null]);

    $this->postJson('/v1/instances/register', registrationPayload(InstanceRegistrationCode::mint($organization, $account)->plainTextCode))
        ->assertCreated();
    $this->postJson('/v1/instances/register', registrationPayload(InstanceRegistrationCode::mint($organization, $account)->plainTextCode, ['host' => null]))
        ->assertCreated();

    expect(Instance::count())->toBe(4)->and($revoked->fresh()->status)->toBe(Instance::STATUS_REVOKED);
});
