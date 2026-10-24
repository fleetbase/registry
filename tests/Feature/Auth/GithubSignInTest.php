<?php

use App\Auth\SessionGuard;
use App\Mail\VerificationCodeMail;
use App\Models\Account;
use App\Models\Event;
use App\Models\Membership;
use App\Models\Token;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GithubUser;

beforeEach(function () {
    config([
        'services.github.client_id' => 'github-client',
        'services.github.client_secret' => 'github-secret',
        'services.github.redirect' => 'http://localhost/auth/github/callback',
    ]);
});

/** A GitHub user as Socialite returns it; `email` is the verified primary email or null. */
function fakeGithubUser(array $attributes = []): GithubUser
{
    $attributes += ['id' => 4242, 'nickname' => 'patdev', 'name' => 'Pat Dev', 'email' => 'pat@example.com', 'avatar' => 'https://avatars.githubusercontent.com/u/4242'];

    return (new GithubUser)->setRaw($attributes)->map($attributes);
}

/** GitHub sends the browser back; Socialite returns the given user. */
function githubCallback(GithubUser $user): TestResponse
{
    Socialite::shouldReceive('driver->user')->andReturn($user);

    return test()->get('/auth/github/callback?code=abc&state=xyz');
}

/** The account the response's session is signed in as, or null. */
function signedInAs(TestResponse $response): ?Account
{
    $uuid = app('session.store')->get(SessionGuard::SESSION_KEY);

    return $uuid ? Token::query()->active()->where('uuid', $uuid)->where('prefix', Token::PREFIX_SESSION)->first()?->account : null;
}

it('sends the visitor to GitHub with the profile and email scopes and remembers where they were going', function () {
    $response = $this->get('/auth/github?intended=/studio/new')->assertRedirect();

    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://github.com/login/oauth/authorize')
        ->and($query['client_id'])->toBe('github-client')
        ->and($query['redirect_uri'])->toBe('http://localhost/auth/github/callback')
        ->and(preg_split('/[ ,]+/', $query['scope']))->toEqualCanonicalizing(['read:user', 'user:email'])
        ->and($query['state'])->not->toBeEmpty();
    $response->assertSessionHas('url.intended', url('/studio/new'));

    // Only paths on this site are remembered.
    $this->get('/auth/github?intended=//evil.example/x')->assertSessionMissing('url.intended');
});

it('says GitHub is not set up when the client id or secret is missing', function () {
    config(['services.github.client_secret' => null]);

    $this->get('/auth/github')->assertRedirect('/sign-in')->assertSessionHas('flash.type', 'error');
    $this->get('/sign-in')->assertInertia(fn ($page) => $page->where('auth.providers.github', false));

    config(['services.github.client_secret' => 'github-secret']);
    $this->get('/sign-in')->assertInertia(fn ($page) => $page->where('auth.providers.github', true));
});

it('creates an account from a new GitHub user and signs it in', function () {
    Mail::fake();

    $response = githubCallback(fakeGithubUser(['nickname' => 'PatDev']))->assertRedirect('/studio');

    $account = Account::where('email', 'pat@example.com')->sole();
    expect($account->github_id)->toBe('4242')
        ->and($account->github_login)->toBe('PatDev')
        ->and($account->username)->toBe('patdev')
        ->and($account->name)->toBe('Pat Dev')
        ->and($account->password)->toBeNull()
        ->and($account->avatar_url)->toBe('https://avatars.githubusercontent.com/u/4242')
        ->and($account->hasVerifiedEmail())->toBeTrue()
        ->and($account->status)->toBe(Account::STATUS_ACTIVE)
        ->and($account->personalOrganization()?->slug)->toBe('patdev')
        ->and($account->roleIn($account->personalOrganization()))->toBe(Membership::ROLE_OWNER)
        ->and(signedInAs($response)?->is($account))->toBeTrue()
        ->and(Event::where('kind', 'account.login')->sole()->payload)->toMatchArray(['via' => 'github']);

    Mail::assertNotSent(VerificationCodeMail::class);
});

it('leaves the username empty when the GitHub login is taken', function () {
    Account::factory()->create(['username' => 'patdev']);

    githubCallback(fakeGithubUser())->assertRedirect('/studio');

    $account = Account::where('email', 'pat@example.com')->sole();
    expect($account->username)->toBeNull()
        ->and($account->personalOrganization()->slug)->toBe('patdev');
});

it('lands on the page the visitor was going to', function () {
    $session = $this->get('/auth/github?intended=/studio/new')->getCookie(config('session.cookie'))->getValue();

    $this->withCookie(config('session.cookie'), $session);
    githubCallback(fakeGithubUser())->assertRedirect(url('/studio/new'));
});

it('links GitHub to the account with the same verified email', function () {
    $account = Account::factory()->unverified()->create(['email' => 'pat@example.com', 'avatar_url' => null]);

    $response = githubCallback(fakeGithubUser(['email' => 'Pat@Example.com']))->assertRedirect('/studio');

    $account->refresh();
    expect(Account::count())->toBe(1)
        ->and($account->github_id)->toBe('4242')
        ->and($account->github_login)->toBe('patdev')
        ->and($account->hasVerifiedEmail())->toBeTrue()
        ->and($account->status)->toBe(Account::STATUS_ACTIVE)
        ->and(signedInAs($response)?->is($account))->toBeTrue()
        ->and(Event::where('kind', 'account.github_connected')->count())->toBe(1);
});

it('signs in the account already linked to the GitHub user, whatever its email', function () {
    $account = Account::factory()->create(['email' => 'old@example.com', 'github_id' => '4242', 'github_login' => 'old-login']);

    $response = githubCallback(fakeGithubUser(['email' => null]))->assertRedirect('/studio');

    expect(Account::count())->toBe(1)
        ->and($account->fresh()->github_login)->toBe('patdev')
        ->and(signedInAs($response)?->is($account))->toBeTrue();
});

it('refuses a suspended account', function () {
    $account = Account::factory()->suspended()->create(['github_id' => '4242']);

    $response = githubCallback(fakeGithubUser())
        ->assertRedirect('/sign-in')
        ->assertSessionHas('flash', ['type' => 'error', 'message' => 'This account is suspended.']);

    expect(signedInAs($response))->toBeNull();

    // Also when matched by email.
    $account->forceFill(['github_id' => null, 'email' => 'pat@example.com'])->save();
    githubCallback(fakeGithubUser())->assertRedirect('/sign-in');
    expect($account->fresh()->github_id)->toBeNull();
});

it('needs a verified email to create an account', function () {
    githubCallback(fakeGithubUser(['email' => null]))
        ->assertRedirect('/sign-in')
        ->assertSessionHas('flash.type', 'error');

    expect(Account::count())->toBe(0);
});

it('does not link an email account that is connected to another GitHub user', function () {
    Account::factory()->create(['email' => 'pat@example.com', 'github_id' => '999']);

    githubCallback(fakeGithubUser())->assertRedirect('/sign-in')->assertSessionHas('flash.type', 'error');
});

it('sends cancelled and expired GitHub sign-ins back to sign in with a message', function () {
    $this->get('/auth/github/callback?error=access_denied&state=xyz')
        ->assertRedirect('/sign-in')
        ->assertSessionHas('flash', ['type' => 'error', 'message' => 'GitHub sign-in was cancelled.']);

    Socialite::shouldReceive('driver->user')->andThrow(new InvalidStateException);
    $this->get('/auth/github/callback?code=abc&state=wrong')
        ->assertRedirect('/sign-in')
        ->assertSessionHas('flash.type', 'error');

    expect(Account::count())->toBe(0);
});

it('connects GitHub to the signed-in account instead of signing in', function () {
    $account = Account::factory()->create(['email' => 'me@example.com']);
    $token = actingOnWebsite($account);

    githubCallback(fakeGithubUser(['email' => 'someone-else@example.com']))
        ->assertRedirect('/account/profile')
        ->assertSessionHas('flash', ['type' => 'ok', 'message' => 'GitHub connected as @patdev.']);

    expect($account->fresh()->github_id)->toBe('4242')
        ->and($account->fresh()->github_login)->toBe('patdev')
        ->and(Account::count())->toBe(1)
        ->and($token->fresh()->revoked_at)->toBeNull();
});

it('refuses to connect a GitHub user that belongs to another account', function () {
    Account::factory()->create(['github_id' => '4242', 'github_login' => 'patdev']);
    $account = Account::factory()->create();
    actingOnWebsite($account);

    githubCallback(fakeGithubUser())
        ->assertRedirect('/account/profile')
        ->assertSessionHas('flash.type', 'error');

    expect($account->fresh()->github_id)->toBeNull()
        ->and(Event::where('kind', 'account.github_conflict')->count())->toBe(1);
});

it('disconnects GitHub only when the account has a password', function () {
    $account = Account::factory()->create(['github_id' => '4242', 'github_login' => 'patdev']);
    actingAsAccount($account);

    $this->getJson('/v1/account')->assertJsonPath('github_login', 'patdev')->assertJsonPath('has_password', true);

    $this->deleteJson('/v1/account/github')
        ->assertOk()
        ->assertJsonPath('github_login', null);
    expect($account->fresh()->github_id)->toBeNull()
        ->and(Event::where('kind', 'account.github_disconnected')->count())->toBe(1);

    $this->deleteJson('/v1/account/github')->assertStatus(422);

    $githubOnly = Account::factory()->create(['github_id' => '777', 'github_login' => 'rin', 'password' => null]);
    actingAsAccount($githubOnly);
    $this->deleteJson('/v1/account/github')
        ->assertStatus(422)
        ->assertJsonPath('code', 'password_required');
    expect($githubOnly->fresh()->github_id)->toBe('777');
});

it('needs a signed-in account to disconnect', function () {
    $this->deleteJson('/v1/account/github')->assertStatus(401);
});

it('keeps one registry account per GitHub account', function () {
    Account::factory()->create(['github_id' => '4242']);

    expect(fn () => Account::factory()->create(['github_id' => '4242']))->toThrow(QueryException::class);
});
