<?php

use App\Models\Account;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Token;
use Inertia\Testing\AssertableInertia as Assert;

it('renders sign in, create account and verify to visitors', function () {
    $this->get('/sign-in?email=pat@example.test&verified=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Auth/SignIn')
            ->where('email', 'pat@example.test')
            ->where('verified', true)
            ->where('intended', null)
            ->where('auth.account', null));

    $this->get('/register')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Register'));

    $this->get('/verify?email=pat@example.test&sent=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/Verify')->where('email', 'pat@example.test')->where('sent', true));
});

it('remembers where a visitor was going when it sends them to sign in', function () {
    // The array session store does not outlive a request; the database store does.
    config(['session.driver' => 'database']);

    $response = $this->get('/studio/new')->assertRedirect('/sign-in');
    $session = $response->getCookie(config('session.cookie'))->getValue();

    $this->withCookie(config('session.cookie'), $session)
        ->get('/sign-in')
        ->assertInertia(fn (Assert $page) => $page->component('Auth/SignIn')->where('intended', url('/studio/new')));
});

it('sends a signed-in account from sign in to the Studio', function () {
    [$account] = publisher();
    actingOnWebsite($account);

    $this->get('/sign-in')->assertRedirect('/studio');
});

it('needs a signed-in account for profile, organizations and tokens', function (string $path) {
    $this->get($path)->assertRedirect('/sign-in');
})->with(['/account', '/account/profile', '/account/organizations', '/account/tokens']);

it('renders the profile page with the shared account', function () {
    [$account] = publisher();
    actingOnWebsite($account);

    $this->get('/account')->assertRedirect('/account/organizations');
    $this->get('/account/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Account/Profile')->where('auth.account.email', $account->email));
});

it('lists the account’s organizations and selects the first shared one with its members', function () {
    [$account, $organization] = publisher();
    $personal = Organization::factory()->create(['is_personal' => true, 'name' => 'Pat Personal']);
    $account->organizations()->attach($personal->uuid, ['role' => Membership::ROLE_OWNER]);
    $colleague = Account::factory()->create(['name' => 'Rin Kato']);
    $colleague->organizations()->attach($organization->uuid, ['role' => Membership::ROLE_ADMIN]);
    actingOnWebsite($account);

    $this->get('/account/organizations')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Organizations')
            ->has('organizations', 2)
            ->where('organizations.0.slug', $personal->slug)
            ->where('organizations.1.members_count', 2)
            ->where('organization.slug', $organization->slug)
            ->where('organization.role', Membership::ROLE_OWNER)
            ->has('organization.members', 2)
            ->where('organization.members.0.email', $account->email)
            ->where('organization.members.0.role', Membership::ROLE_OWNER)
            ->where('organization.members.1.name', 'Rin Kato')
            ->where('organization.members.1.role', Membership::ROLE_ADMIN));

    $this->get("/account/organizations/{$personal->slug}")
        ->assertInertia(fn (Assert $page) => $page->where('organization.slug', $personal->slug)->where('organization.is_personal', true)->has('organization.members', 1));
});

it('answers 404 for an organization the account is not in', function () {
    [$account] = publisher();
    $other = Organization::factory()->create();
    actingOnWebsite($account);

    $this->get("/account/organizations/{$other->slug}")->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
});

it('lists personal tokens, masked, without website session tokens', function () {
    [$account, $organization] = publisher();
    Token::issue($account, 'GitHub Actions: publish', [Token::SCOPE_PUBLISH], now()->addDays(90), $organization->uuid);
    Token::issue($account, 'Old CI token', [Token::SCOPE_INSTALL], now()->addDays(6));
    Token::issue($account, 'Revoked', [Token::SCOPE_READ])->revoke();
    actingOnWebsite($account);

    $this->get('/account/tokens')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Tokens')
            ->has('tokens', 2)
            ->where('tokens', fn ($tokens) => collect($tokens)->every(fn ($token) => ! str_starts_with((string) $token['prefix'], Token::PREFIX_SESSION) && ! array_key_exists('token', $token)))
            ->where('tokens', fn ($tokens) => collect($tokens)->pluck('name')->sort()->values()->all() === ['GitHub Actions: publish', 'Old CI token'])
            ->where('tokens', fn ($tokens) => collect($tokens)->firstWhere('name', 'GitHub Actions: publish')['organization'] === $organization->public_id)
            ->has('tokens.0.masked')
            ->has('tokens.0.expires_at')
            ->has('tokens.0.last_used_ip'));
});

it('renders a publisher’s public profile with its extensions', function () {
    $package = makePackage('npm', '@acme/dock-engine', extension: ['name' => 'Dock Scheduler', 'slug' => 'dock-scheduler']);
    makeRelease($package, '1.0.0');
    $organization = $package->extension->organization;
    $organization->forceFill(['bio' => 'Warehouse software.', 'website' => 'https://acme.example', 'verified_at' => now()])->save();

    $this->get("/publishers/{$organization->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Publisher/Show')
            ->where('publisher.slug', $organization->slug)
            ->where('publisher.bio', 'Warehouse software.')
            ->where('publisher.is_verified', true)
            ->where('publisher.extensions_count', 1)
            ->where('publisher.extensions.0.slug', 'dock-scheduler')
            ->where('publisher.extensions.0.latest_version', '1.0.0'));

    $this->get('/publishers/nobody-here')->assertNotFound();
});

it('renders forgot password to visitors, opening the code step from the reset email', function () {
    $this->get('/forgot-password')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword')->where('email', null)->where('sent', false));

    $this->get('/forgot-password?email=pat@example.test&sent=1')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Auth/ForgotPassword')->where('email', 'pat@example.test')->where('sent', true));

    expect(route('password.request', absolute: false))->toBe('/forgot-password');

    // After a reset the page sends the visitor to sign in with `reset`.
    $this->get('/sign-in?email=pat@example.test&reset=1')
        ->assertInertia(fn (Assert $page) => $page->component('Auth/SignIn')->where('reset', true)->where('verified', false));
});

it('shares whether Sign in with GitHub is set up', function () {
    config(['services.github.client_id' => null, 'services.github.client_secret' => null]);
    $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('auth.providers.github', false));

    config(['services.github.client_id' => 'github-client', 'services.github.client_secret' => 'github-secret']);
    $this->get('/register')->assertInertia(fn (Assert $page) => $page->where('auth.providers.github', true));
});

it('gives the profile page the GitHub connection and whether the account has a password', function () {
    $account = Account::factory()->create(['github_id' => '4242', 'github_login' => 'patdev', 'password' => null]);
    actingOnWebsite($account);

    $this->get('/account/profile')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Account/Profile')
            ->where('auth.account.github_login', 'patdev')
            ->where('auth.account.has_password', false)
            ->missing('auth.account.github_id'));
});
