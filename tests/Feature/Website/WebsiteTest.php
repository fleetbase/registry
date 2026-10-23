<?php

use Inertia\Testing\AssertableInertia as Assert;

it('renders Discover with the catalog, filters and compatibility for the visitor’s console', function () {
    $fits = makePackage('npm', '@acme/fits-engine', extension: ['name' => 'Fits', 'slug' => 'fits']);
    makeRelease($fits, '1.0.0', manifest: ['compat' => ['console' => '>=0.7.60']])->forceFill(['compat' => ['console' => '>=0.7.60']])->save();
    $old = makePackage('npm', '@acme/old-engine', extension: ['name' => 'Old', 'slug' => 'old']);
    makeRelease($old, '1.0.0')->forceFill(['compat' => ['console' => '<0.7.0']])->save();

    $this->withUnencryptedCookie('registry_console', '0.7.69')
        ->get('/?sort=name')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Discover/Index')
            ->where('filters', ['sort' => 'name'])
            ->where('console', '0.7.69')
            ->has('results.data', 2)
            ->where('results.data.0.slug', 'fits')
            ->where('results.data.0.compatible', true)
            ->where('results.data.1.compatible', false)
            ->has('categories')
            ->where('auth.account', null));
});

it('renders an extension with its releases and checks another console version on request', function () {
    $package = makePackage('npm', '@acme/dock-engine', extension: ['name' => 'Dock Scheduler', 'slug' => 'dock-scheduler']);
    makeRelease($package, '0.2.0', publishedAt: now()->subDays(10)->toDateTimeString())->forceFill(['compat' => ['console' => '>=0.7.0']])->save();
    makeRelease($package, '0.3.0')->forceFill(['compat' => ['console' => '>=0.7.60']])->save();

    $this->get('/extensions/dock-scheduler')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Extension/Show')
            ->where('extension.name', 'Dock Scheduler')
            ->has('releases', 2)
            ->where('releases.0.version', '0.3.0')
            ->where('compatibility', null));

    $this->get('/extensions/dock-scheduler?check=0.7.40')
        ->assertInertia(fn (Assert $page) => $page
            ->where('compatibility.compatible', false)
            ->where('compatibility.newest_compatible', '0.2.0'));
});

it('renders a publisher profile', function () {
    $package = makePackage('npm', '@acme/x-engine', extension: ['name' => 'X']);
    $organization = $package->extension->organization;

    $this->get("/publishers/{$organization->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Publisher/Show')->where('publisher.slug', $organization->slug)->has('publisher.extensions', 1));
});

it('remembers the console version in a cookie', function () {
    $this->from('/')->post('/console', ['version' => '0.7.69'])->assertRedirect('/')->assertCookie('registry_console', '0.7.69', false);
    $this->from('/')->post('/console', ['version' => 'nope'])->assertSessionHasErrors('version');
});

it('renders website 404 and 403 pages, and sends visitors to sign in', function () {
    $this->get('/extensions/missing')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    $this->get('/no/such/page')->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error'));
    $this->get('/studio')->assertRedirect('/sign-in');

    [$account] = publisher();
    actingOnWebsite($account);
    $this->get('/review')->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403));
});

it('shares the signed-in account', function () {
    [$account] = publisher();
    actingOnWebsite($account);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('auth.account.email', $account->email)->has('auth.account.organizations', 1));
});

it('keeps API, package and health paths out of the website', function () {
    $this->get('/v1/nope')->assertNotFound()->assertExactJson(['errors' => ['Resource not found.']]);
    $this->get('/v1/catalog/nope/deeper')->assertNotFound()->assertExactJson(['errors' => ['Resource not found.']]);
    $this->postJson('/v1/nope')->assertNotFound()->assertExactJson(['errors' => ['Resource not found.']]);
    $this->get('/composer/nope')->assertNotFound()->assertJsonPath('error', 'Not found.');
    $this->get('/npm/-/nope/deeper')->assertNotFound();
    $this->get('/up')->assertOk();
});

it('moves the service info to GET /v1', function () {
    $this->getJson('/v1')
        ->assertOk()
        ->assertJsonPath('service', config('app.name'))
        ->assertJsonPath('version', config('registry.version'))
        ->assertJsonPath('catalog', url('/v1/catalog/extensions'));
});
