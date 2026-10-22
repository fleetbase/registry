<?php

use App\Models\Account;
use App\Models\Instance;
use App\Models\InstanceRegistrationCode;
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
