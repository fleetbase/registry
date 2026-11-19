<?php

use App\Models\Account;
use App\Models\Organization;
use App\Models\Token;

it('returns the authenticated account with organizations', function () {
    $account = Account::factory()->create();
    $organization = Organization::factory()->create();
    $account->organizations()->attach($organization->uuid, ['role' => 'publisher']);
    actingAsAccount($account);

    $this->getJson('/v1/account')
        ->assertOk()
        ->assertJsonPath('id', $account->public_id)
        ->assertJsonPath('email', $account->email)
        ->assertJsonPath('organizations.0.slug', $organization->slug)
        ->assertJsonPath('organizations.0.role', 'publisher')
        ->assertJsonMissingPath('uuid')
        ->assertJsonMissingPath('password');
});

it('requires a bearer token', function () {
    $this->getJson('/v1/account')->assertStatus(401);
    $this->withHeader('Authorization', 'Bearer flbp_doesnotexist')->getJson('/v1/account')->assertStatus(401);
});

it('rejects expired, revoked and foreign-prefix tokens', function () {
    $account = Account::factory()->create();

    $expired = Token::issue($account, 'expired', ['*'], now()->subMinute());
    $this->withHeader('Authorization', 'Bearer '.$expired->plainTextToken)->getJson('/v1/account')->assertStatus(401);

    $revoked = Token::issue($account, 'revoked');
    $revoked->revoke();
    $this->withHeader('Authorization', 'Bearer '.$revoked->plainTextToken)->getJson('/v1/account')->assertStatus(401);

    $instance = Token::issue($account, 'instance', ['*'], null, null, Token::PREFIX_INSTANCE);
    $this->withHeader('Authorization', 'Bearer '.$instance->plainTextToken)->getJson('/v1/account')->assertStatus(401);
});

it('rejects tokens of suspended accounts and records usage on success', function () {
    $account = Account::factory()->create();
    $token = actingAsAccount($account);

    $this->getJson('/v1/account')->assertOk();
    expect($token->fresh()->last_used_at)->not->toBeNull();

    $account->forceFill(['status' => Account::STATUS_SUSPENDED])->save();
    $this->getJson('/v1/account')->assertStatus(401);
});
