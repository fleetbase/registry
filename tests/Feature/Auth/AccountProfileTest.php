<?php

use App\Models\Account;
use App\Models\Token;
use Illuminate\Support\Facades\Hash;

it('updates the name, username and timezone', function () {
    $account = Account::factory()->create(['username' => 'old-name']);
    Account::factory()->create(['username' => 'taken']);
    actingAsAccount($account);

    $this->patchJson('/v1/account', ['name' => 'New Name', 'username' => 'new-name', 'timezone' => 'Asia/Singapore'])
        ->assertOk()
        ->assertJsonPath('name', 'New Name')
        ->assertJsonPath('username', 'new-name')
        ->assertJsonPath('timezone', 'Asia/Singapore');

    // Keeping one's own username is fine; another account's is not.
    $this->patchJson('/v1/account', ['username' => 'new-name'])->assertOk();
    $this->patchJson('/v1/account', ['username' => 'taken'])->assertStatus(422)->assertJsonPath('fields.username.0', 'The username has already been taken.');
    $this->patchJson('/v1/account', ['username' => 'Bad Name'])->assertStatus(422);
    $this->patchJson('/v1/account', ['timezone' => 'Mars/Olympus'])->assertStatus(422);
    $this->patchJson('/v1/account', ['email' => 'x@example.com'])->assertOk();

    expect($account->fresh()->email)->not->toBe('x@example.com');
});

it('changes the password with the current one and ends other website sessions', function () {
    $account = Account::factory()->create(['password' => 'password']);
    $cookies = websiteSignIn($account);
    $otherSession = Token::issue($account, 'web session', ['*'], now()->addHour(), null, Token::PREFIX_SESSION);
    $currentSession = Token::where('prefix', Token::PREFIX_SESSION)->whereKeyNot($otherSession->getKey())->sole();

    websiteRequest('POST', '/v1/account/password', $cookies, ['current_password' => 'wrong', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
        ->assertStatus(422)
        ->assertJsonPath('fields.current_password.0', 'The current password is incorrect.');
    websiteRequest('POST', '/v1/account/password', $cookies, ['current_password' => 'password', 'password' => 'new-pass-123', 'password_confirmation' => 'other'])
        ->assertStatus(422);
    websiteRequest('POST', '/v1/account/password', $cookies, ['current_password' => 'password', 'password' => 'short1', 'password_confirmation' => 'short1'])
        ->assertStatus(422);

    websiteRequest('POST', '/v1/account/password', $cookies, ['current_password' => 'password', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
        ->assertOk();

    expect(Hash::check('new-pass-123', $account->fresh()->password))->toBeTrue()
        ->and($otherSession->fresh()->revoked_at)->not->toBeNull()
        ->and($currentSession->fresh()->revoked_at)->toBeNull();
    websiteRequest('GET', '/v1/account', $cookies)->assertOk();
});
