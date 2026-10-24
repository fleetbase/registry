<?php

use App\Models\Account;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Token;

it('starts a session and the XSRF cookie before sign-in', function () {
    $response = websiteRequest('GET', '/v1/auth/session', [])
        ->assertOk()
        ->assertExactJson(['authenticated' => false, 'account' => null]);

    $cookies = collect($response->headers->getCookies())->keyBy(fn ($cookie) => $cookie->getName());
    $session = $cookies->get(config('session.cookie'));

    expect($session)->not->toBeNull()
        ->and($session->isHttpOnly())->toBeTrue()
        ->and($session->getSameSite())->toBe('lax')
        ->and($cookies->get('XSRF-TOKEN'))->not->toBeNull()
        ->and($cookies->get('XSRF-TOKEN')->isHttpOnly())->toBeFalse();
});

it('signs in with a cookie and binds the session to a session token', function () {
    $account = Account::factory()->create(['email' => 'dev@example.com', 'password' => 'password']);
    $cookies = cookiesFrom(websiteRequest('GET', '/v1/auth/session', []));

    $response = websiteRequest('POST', '/v1/auth/session', $cookies, ['email' => 'DEV@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('account.email', 'dev@example.com')
        ->assertJsonPath('account.is_staff', false)
        ->assertJsonMissingPath('token');

    // The session id is regenerated on sign-in.
    $signedIn = cookiesFrom($response, $cookies);
    expect($signedIn[config('session.cookie')])->not->toBe($cookies[config('session.cookie')]);

    $token = Token::where('prefix', Token::PREFIX_SESSION)->sole();
    expect($token->account_uuid)->toBe($account->uuid)
        ->and($token->scopes)->toBe(['*'])
        ->and($token->name)->toBe('web session '.now()->toDateString())
        ->and($token->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)))->toBeTrue()
        ->and($token->plainTextToken)->toBeNull()
        ->and(Event::where('kind', 'account.login')->sole()->payload)->toMatchArray(['via' => 'session', 'token' => $token->public_id]);

    websiteRequest('GET', '/v1/auth/session', $signedIn)
        ->assertOk()
        ->assertJsonPath('authenticated', true)
        ->assertJsonPath('account.email', 'dev@example.com');

    websiteRequest('GET', '/v1/account', $signedIn)->assertOk()->assertJsonPath('id', $account->public_id);
    expect($token->fresh()->last_used_at)->not->toBeNull();
});

it('answers sign-in errors like login', function () {
    Account::factory()->create(['email' => 'dev@example.com', 'password' => 'password']);
    Account::factory()->unverified()->create(['email' => 'new@example.com', 'password' => 'password']);
    Account::factory()->suspended()->create(['email' => 'gone@example.com', 'password' => 'password']);
    $cookies = cookiesFrom(websiteRequest('GET', '/v1/auth/session', []));

    websiteRequest('POST', '/v1/auth/session', $cookies, ['email' => 'dev@example.com', 'password' => 'nope'])
        ->assertStatus(401)
        ->assertExactJson(['errors' => ['Invalid email or password.']]);
    websiteRequest('POST', '/v1/auth/session', $cookies, ['email' => 'new@example.com', 'password' => 'password'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'email_unverified');
    websiteRequest('POST', '/v1/auth/session', $cookies, ['email' => 'gone@example.com', 'password' => 'password'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'account_suspended');

    expect(Token::count())->toBe(0);
});

it('reaches account, publisher and admin routes with the session cookie', function () {
    [$publisher, , $listing] = publisher();
    $publisher->forceFill(['password' => 'password'])->save();
    $cookies = websiteSignIn($publisher);

    websiteRequest('GET', '/v1/account/tokens', $cookies)->assertOk();
    websiteRequest('GET', '/v1/publisher/extensions', $cookies)->assertOk()->assertJsonPath('0.slug', $listing->slug);
    websiteRequest('PATCH', '/v1/publisher/extensions/'.$listing->slug, $cookies, ['subtitle' => 'From the website'])
        ->assertOk()
        ->assertJsonPath('subtitle', 'From the website');
    websiteRequest('GET', '/v1/admin/releases', $cookies)->assertForbidden();

    $staff = staffAccount();
    $staff->forceFill(['password' => 'password'])->save();
    $staffCookies = websiteSignIn($staff);

    websiteRequest('GET', '/v1/auth/session', $staffCookies)->assertJsonPath('account.is_staff', true);
    websiteRequest('GET', '/v1/admin/releases', $staffCookies)->assertOk();
    websiteRequest('GET', '/v1/admin/review-items', $staffCookies)->assertOk()->assertJsonPath('0.code', 'R1');
});

it('refuses unsafe session requests without the CSRF header', function () {
    $account = Account::factory()->create(['password' => 'password']);
    $cookies = websiteSignIn($account);

    websiteRequest('POST', '/v1/account/tokens', $cookies, ['name' => 'cli', 'scopes' => ['read']], csrf: false)
        ->assertStatus(419)
        ->assertExactJson(['errors' => ['CSRF token mismatch.']]);
    websiteRequest('PATCH', '/v1/account', $cookies, ['name' => 'Nope'], csrf: false)->assertStatus(419);
    websiteRequest('DELETE', '/v1/auth/session', $cookies, csrf: false)->assertStatus(419);

    // Sign-in itself needs the header too (login CSRF), even before any cookie exists.
    websiteRequest('POST', '/v1/auth/session', [], ['email' => $account->email, 'password' => 'password'])
        ->assertStatus(419)
        ->assertExactJson(['errors' => ['CSRF token mismatch.']]);

    websiteRequest('POST', '/v1/account/tokens', $cookies, ['name' => 'cli', 'scopes' => ['read']])->assertCreated();
    expect($account->tokens()->where('prefix', Token::PREFIX_PERSONAL)->count())->toBe(1);
});

it('leaves bearer requests without session, cookies or CSRF', function () {
    $account = Account::factory()->create();
    actingAsAccount($account);

    $response = $this->postJson('/v1/account/tokens', ['name' => 'cli', 'scopes' => ['read']])->assertCreated();
    expect($response->headers->getCookies())->toBe([]);

    $response = $this->getJson('/v1/account')->assertOk();
    expect($response->headers->getCookies())->toBe([]);

    // CLI login and registration take no cookies and need no CSRF token.
    $this->withHeaders(['Authorization' => ''])->postJson('/v1/auth/login', ['email' => $account->email, 'password' => 'password'])->assertOk();
});

it('ignores the session cookie when a bearer token is sent', function () {
    $sessionAccount = Account::factory()->create(['password' => 'password']);
    $cookies = websiteSignIn($sessionAccount);
    $other = Account::factory()->create();
    $token = Token::issue($other, 'cli');

    $response = test()->call('GET', '/v1/account', [], $cookies, [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token->plainTextToken])
        ->assertOk()
        ->assertJsonPath('id', $other->public_id);
    expect($response->headers->getCookies())->toBe([]);

    // A bad bearer token does not fall back to the cookie.
    test()->call('GET', '/v1/account', [], $cookies, [], ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer flbp_nope'])->assertUnauthorized();
});

it('signs out by revoking the session token', function () {
    $account = Account::factory()->create(['password' => 'password']);
    $cookies = websiteSignIn($account);
    $token = Token::where('prefix', Token::PREFIX_SESSION)->sole();

    $response = websiteRequest('DELETE', '/v1/auth/session', $cookies)->assertOk()->assertJsonPath('authenticated', false);
    expect($token->fresh()->revoked_at)->not->toBeNull();

    // The old cookies no longer authenticate; the session was invalidated.
    websiteRequest('GET', '/v1/account', $cookies)->assertUnauthorized();
    websiteRequest('GET', '/v1/auth/session', cookiesFrom($response, $cookies))->assertJsonPath('authenticated', false);

    // Signing out without a session still answers 200.
    $fresh = cookiesFrom(websiteRequest('GET', '/v1/auth/session', []));
    websiteRequest('DELETE', '/v1/auth/session', $fresh)->assertOk();
});

it('never accepts a session token as a bearer token', function () {
    $account = Account::factory()->create();
    $session = Token::issue($account, 'web session', ['*'], now()->addHour(), null, Token::PREFIX_SESSION);

    $this->withHeader('Authorization', 'Bearer '.$session->plainTextToken)->getJson('/v1/account')->assertUnauthorized();
});

it('stops a suspended account\'s session', function () {
    $account = Account::factory()->create(['password' => 'password']);
    $cookies = websiteSignIn($account);
    websiteRequest('GET', '/v1/account', $cookies)->assertOk();

    $account->forceFill(['status' => Account::STATUS_SUSPENDED])->save();

    websiteRequest('GET', '/v1/account', $cookies)->assertUnauthorized();
    websiteRequest('GET', '/v1/auth/session', $cookies)->assertJsonPath('authenticated', false);
});

it('slides the session token expiry once half the lifetime is used', function () {
    $account = Account::factory()->create(['password' => 'password']);
    $cookies = websiteSignIn($account);
    $token = Token::where('prefix', Token::PREFIX_SESSION)->sole();

    $token->forceFill(['expires_at' => now()->addMinutes(90)])->save();
    websiteRequest('GET', '/v1/account', $cookies)->assertOk();
    expect($token->fresh()->expires_at->between(now()->addMinutes(89), now()->addMinutes(91)))->toBeTrue();

    $token->forceFill(['expires_at' => now()->addMinutes(10)])->save();
    websiteRequest('GET', '/v1/account', $cookies)->assertOk();
    expect($token->fresh()->expires_at->between(now()->addMinutes(119), now()->addMinutes(121)))->toBeTrue();

    $token->forceFill(['expires_at' => now()->subMinute()])->save();
    websiteRequest('GET', '/v1/account', $cookies)->assertUnauthorized();
});

it('keeps session tokens out of the token list', function () {
    $account = Account::factory()->create(['password' => 'password']);
    $cookies = websiteSignIn($account);
    $personal = Token::issue($account, 'cli');
    $session = Token::where('prefix', Token::PREFIX_SESSION)->sole();

    websiteRequest('GET', '/v1/account/tokens', $cookies)
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $personal->public_id);

    websiteRequest('DELETE', '/v1/account/tokens/'.$session->public_id, $cookies)->assertNotFound();
});

it('keeps logout token-only', function () {
    $account = Account::factory()->create(['password' => 'password']);
    $cookies = websiteSignIn($account);

    websiteRequest('POST', '/v1/auth/logout', $cookies)->assertUnauthorized();
});

it('reports staff and organization flags on the account', function () {
    $account = Account::factory()->create();
    $organization = Organization::factory()->staff()->verified()->create();
    $account->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_MEMBER]);
    actingAsAccount($account);

    $this->getJson('/v1/account')
        ->assertOk()
        ->assertJsonPath('is_staff', true)
        ->assertJsonPath('organizations.0.is_staff', true)
        ->assertJsonPath('organizations.0.verified_at', $organization->verified_at->toJSON());
});
