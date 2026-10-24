<?php

use App\Models\Account;
use App\Models\Organization;
use App\Models\Token;

it('creates a scoped personal token and shows the plaintext once', function () {
    $account = Account::factory()->create();
    actingAsAccount($account);

    $response = $this->postJson('/v1/account/tokens', [
        'name' => 'ci publisher',
        'scopes' => ['publish', 'read'],
        'expires_in_days' => 90,
    ])->assertCreated()
        ->assertJsonPath('token_details.name', 'ci publisher')
        ->assertJsonPath('token_details.scopes', ['publish', 'read']);

    $plain = $response->json('token');
    expect($plain)->toStartWith('flbp_');
    expect($response->json('token_details.masked'))->toBe('flbp_…'.substr($plain, -4));

    $list = $this->getJson('/v1/account/tokens')->assertOk()->json();
    expect($list)->toHaveCount(2); // the acting token + the new one
    expect(collect($list)->pluck('masked')->all())->toContain('flbp_…'.substr($plain, -4));
    expect(json_encode($list))->not->toContain($plain);
});

it('validates scopes, lifetime and organization membership', function () {
    $account = Account::factory()->create();
    actingAsAccount($account);

    $invalid = $this->postJson('/v1/account/tokens', ['name' => 'x', 'scopes' => ['admin']])->assertStatus(422);
    expect($invalid->json('fields'))->toHaveKey('scopes.0');

    $this->postJson('/v1/account/tokens', ['name' => 'x', 'scopes' => ['read'], 'expires_in_days' => 400])
        ->assertStatus(422);

    $foreign = Organization::factory()->create();
    $this->postJson('/v1/account/tokens', ['name' => 'x', 'scopes' => ['read'], 'organization' => $foreign->slug])
        ->assertStatus(422)->assertJsonPath('errors.0', 'You are not a member of that organization.');

    $account->organizations()->attach($foreign->uuid, ['role' => 'publisher']);
    $this->postJson('/v1/account/tokens', ['name' => 'x', 'scopes' => ['read'], 'organization' => $foreign->slug])
        ->assertCreated()->assertJsonPath('token_details.organization', $foreign->public_id);
});

it('revokes a token by public id and refuses other accounts tokens', function () {
    $account = Account::factory()->create();
    $other = Account::factory()->create();
    $otherToken = Token::issue($other, 'theirs');
    actingAsAccount($account);

    $mine = Token::issue($account, 'mine');

    $this->deleteJson('/v1/account/tokens/'.$mine->public_id)->assertOk()->assertJsonPath('revoked', true);
    expect($mine->fresh()->isRevoked())->toBeTrue();

    $this->deleteJson('/v1/account/tokens/'.$otherToken->public_id)->assertStatus(404);
    expect($otherToken->fresh()->isRevoked())->toBeFalse();

    $this->withHeader('Authorization', 'Bearer '.$mine->plainTextToken)->getJson('/v1/account')->assertStatus(401);
});

it('enforces token scopes through the scope middleware', function () {
    $account = Account::factory()->create();

    Route::middleware(['auth:token', 'scope:publish'])->get('/v1/_scope-test', fn () => ['ok' => true]);

    actingAsAccount($account, ['read']);
    $this->getJson('/v1/_scope-test')->assertStatus(403);

    actingAsAccount($account, ['publish']);
    $this->getJson('/v1/_scope-test')->assertOk();

    actingAsAccount($account, ['*']);
    $this->getJson('/v1/_scope-test')->assertOk();
});
