<?php

use App\Models\Account;
use App\Models\Token;
use Illuminate\Support\Facades\Hash;

it('logs in with email and password and returns a personal token once', function () {
    $account = Account::factory()->create(['email' => 'dev@example.com', 'password' => 'correct-horse-1']);

    $response = $this->postJson('/v1/auth/login', ['email' => 'DEV@example.com', 'password' => 'correct-horse-1'])
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('account.email', 'dev@example.com')
        ->assertJsonPath('token_details.scopes', ['*']);

    $plain = $response->json('token');
    expect($plain)->toStartWith('flbp_')->toHaveLength(45);

    $token = Token::firstOrFail();
    expect($token->token_hash)->toBe(hash('sha256', $plain))
        ->and($token->last_four)->toBe(substr($plain, -4))
        ->and($token->public_id)->toStartWith('tok_')
        ->and($token->expires_at)->not->toBeNull()
        ->and($account->fresh()->last_login_at)->not->toBeNull();

    // The plaintext is never stored and never shown again.
    expect(Token::where('token_hash', $plain)->exists())->toBeFalse();
    $this->withHeader('Authorization', 'Bearer '.$plain)
        ->getJson('/v1/account/tokens')
        ->assertOk()
        ->assertJsonMissing(['token' => $plain]);
});

it('accepts password hashes produced by core-api (bcrypt via Hash::make)', function () {
    // Simulates an imported cloud user whose hash was produced outside this app. The
    // `hashed` cast rejects costs above BCRYPT_ROUNDS, so production must keep rounds >= 10.
    $imported = password_hash('legacy-pass-1', PASSWORD_BCRYPT, ['cost' => (int) config('hashing.bcrypt.rounds')]);
    Account::factory()->create(['email' => 'cloud@example.com', 'password' => $imported]);

    expect(Hash::check('legacy-pass-1', $imported))->toBeTrue();

    $this->postJson('/v1/auth/login', ['email' => 'cloud@example.com', 'password' => 'legacy-pass-1'])->assertOk();
});

it('rejects bad credentials with a 401 error envelope', function () {
    Account::factory()->create(['email' => 'dev@example.com', 'password' => 'correct-horse-1']);

    $this->postJson('/v1/auth/login', ['email' => 'dev@example.com', 'password' => 'nope'])
        ->assertStatus(401)
        ->assertExactJson(['errors' => ['Invalid email or password.']]);

    $this->postJson('/v1/auth/login', ['email' => 'ghost@example.com', 'password' => 'nope'])->assertStatus(401);
});

it('refuses login until the email is verified', function () {
    Account::factory()->unverified()->create(['email' => 'new@example.com', 'password' => 'correct-horse-1']);

    $this->postJson('/v1/auth/login', ['email' => 'new@example.com', 'password' => 'correct-horse-1'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_unverified');
});

it('refuses login for suspended accounts', function () {
    Account::factory()->suspended()->create(['email' => 'bad@example.com', 'password' => 'correct-horse-1']);

    $this->postJson('/v1/auth/login', ['email' => 'bad@example.com', 'password' => 'correct-horse-1'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'account_suspended');
});

it('honours requested scopes and expiry on login', function () {
    Account::factory()->create(['email' => 'dev@example.com', 'password' => 'correct-horse-1']);

    $response = $this->postJson('/v1/auth/login', [
        'email' => 'dev@example.com',
        'password' => 'correct-horse-1',
        'token_name' => 'flb cli',
        'scopes' => ['read', 'publish'],
        'expires_in_days' => 7,
    ])->assertOk()->assertJsonPath('token_details.name', 'flb cli')->assertJsonPath('token_details.scopes', ['read', 'publish']);

    $token = Token::firstOrFail();
    expect($token->expires_at->diffInDays(now()->addDays(7), true))->toBeLessThan(1);
    expect($response->json('token_details.scopes'))->toBe(['read', 'publish']);
});

it('logs out by revoking the token used for the request', function () {
    $account = Account::factory()->create();
    $token = actingAsAccount($account);

    $this->postJson('/v1/auth/logout')->assertOk();

    expect($token->fresh()->isRevoked())->toBeTrue();
    $this->getJson('/v1/account')->assertStatus(401)->assertExactJson(['errors' => ['Unauthenticated.']]);
});

it('throttles repeated login attempts', function () {
    Account::factory()->create(['email' => 'dev@example.com', 'password' => 'correct-horse-1']);

    foreach (range(1, 5) as $i) {
        $this->postJson('/v1/auth/login', ['email' => 'dev@example.com', 'password' => 'nope'])->assertStatus(401);
    }

    $this->postJson('/v1/auth/login', ['email' => 'dev@example.com', 'password' => 'nope'])->assertStatus(429);
});
