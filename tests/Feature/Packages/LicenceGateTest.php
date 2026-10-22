<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Organization;
use App\Models\Token;
use Illuminate\Support\Str;

const PAID_ROUTES = [
    'npm packument' => '/npm/@acme%2fpro-engine',
    'npm tarball' => '/npm/@acme/pro-engine/-/pro-engine-1.0.0.tgz',
    'composer p2' => '/composer/p2/acme/pro-api.json',
    'composer p2 ~dev' => '/composer/p2/acme/pro-api~dev.json',
    'composer dist' => '/composer/dist/acme/pro-api/1.0.0.tar',
];

/**
 * A paid extension with an npm and a Composer package, both released at 1.0.0.
 */
function paidExtension(): Extension
{
    $npm = makePackage(ExtensionPackage::KIND_NPM, '@acme/pro-engine', paid: true);
    $extension = $npm->extension;
    $composer = $extension->packages()->create(['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => 'acme/pro-api']);
    makeRelease($npm, '1.0.0');
    makeRelease($composer, '1.0.0');

    return $extension;
}

function grantLicence(Extension $extension, Organization $organization, array $attributes = []): Licence
{
    return Licence::create(array_merge([
        'extension_uuid' => $extension->uuid,
        'organization_uuid' => $organization->uuid,
        'kind' => Licence::KIND_PAID,
        'status' => Licence::STATUS_ACTIVE,
    ], $attributes));
}

function buyer(): array
{
    $account = Account::factory()->create();
    $organization = Organization::factory()->create();
    $account->organizations()->attach($organization->uuid, ['role' => 'owner']);

    return [$account, $organization];
}

it('leaves free packages open without a token, even with a bad one', function () {
    $npm = makePackage(ExtensionPackage::KIND_NPM, '@acme/free-engine');
    $composer = $npm->extension->packages()->create(['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => 'acme/free-api']);
    makeRelease($npm, '1.0.0');
    makeRelease($composer, '1.0.0');

    $this->get('/npm/@acme%2ffree-engine')->assertOk();
    $this->get('/npm/@acme/free-engine/-/free-engine-1.0.0.tgz')->assertOk();
    $this->get('/composer/p2/acme/free-api.json')->assertOk();
    $this->get('/composer/dist/acme/free-api/1.0.0.tar')->assertOk();

    $this->withHeader('Authorization', 'Bearer flbp_bogus')->get('/npm/@acme%2ffree-engine')->assertOk();
});

it('answers 401 for paid packages without a usable token', function (string $uri) {
    paidExtension();

    $this->get($uri)->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer realm="Fleetbase Registry"')
        ->assertJsonPath('error', fn (string $m) => str_contains($m, 'paid extension'));

    $this->withHeader('Authorization', 'Bearer flbp_not-a-real-token')->get($uri)->assertUnauthorized();

    [$account] = buyer();
    $revoked = Token::issue($account, 'old', [Token::SCOPE_INSTALL])->revoke();
    $this->withHeader('Authorization', 'Bearer '.$revoked->plainTextToken)->get($uri)->assertUnauthorized();
})->with(PAID_ROUTES);

it('answers 403 for a token without a licence or without the install scope', function (string $uri) {
    paidExtension();
    [$account] = buyer();

    actingAsAccount($account, [Token::SCOPE_INSTALL]);
    $this->get($uri)->assertForbidden()->assertJsonPath('error', fn (string $m) => str_contains($m, 'does not hold an active licence'));

    actingAsAccount($account, [Token::SCOPE_READ, Token::SCOPE_PUBLISH]);
    $this->get($uri)->assertForbidden()->assertJsonPath('error', "This token does not have the 'install' scope.");
})->with(PAID_ROUTES);

it('serves paid packages to an organization with an active licence', function (string $uri) {
    $extension = paidExtension();
    [$account, $organization] = buyer();
    grantLicence($extension, $organization);

    $token = actingAsAccount($account, [Token::SCOPE_INSTALL]);
    $response = $this->get($uri)->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($token->fresh()->last_used_at)->not->toBeNull();
})->with(PAID_ROUTES);

it('ignores expired and revoked licences', function () {
    $extension = paidExtension();
    [$account, $organization] = buyer();
    $licence = grantLicence($extension, $organization, ['expires_at' => now()->subDay()]);
    actingAsAccount($account, [Token::SCOPE_INSTALL]);

    $this->get('/npm/@acme%2fpro-engine')->assertForbidden();

    $licence->forceFill(['expires_at' => null, 'status' => Licence::STATUS_REVOKED])->save();
    $this->get('/npm/@acme%2fpro-engine')->assertForbidden();

    $licence->forceFill(['status' => Licence::STATUS_ACTIVE])->save();
    $this->get('/npm/@acme%2fpro-engine')->assertOk();
});

it('limits a token pinned to an organization to that organization', function () {
    $extension = paidExtension();
    [$account, $licensed] = buyer();
    grantLicence($extension, $licensed);
    $other = Organization::factory()->create();
    $account->organizations()->attach($other->uuid, ['role' => 'member']);

    $pinned = Token::issue($account, 'other org', [Token::SCOPE_INSTALL], organizationUuid: $other->uuid);
    $this->withHeader('Authorization', 'Bearer '.$pinned->plainTextToken)->get('/npm/@acme%2fpro-engine')->assertForbidden();

    $unpinned = Token::issue($account, 'any org', [Token::SCOPE_INSTALL]);
    $this->withHeader('Authorization', 'Bearer '.$unpinned->plainTextToken)->get('/npm/@acme%2fpro-engine')->assertOk();
});

it('lets the publishing organization install its own paid extension', function () {
    $extension = paidExtension();
    $publisher = Account::factory()->create();
    $publisher->organizations()->attach($extension->organization_uuid, ['role' => 'publisher']);

    actingAsAccount($publisher, [Token::SCOPE_INSTALL]);
    $this->get('/composer/dist/acme/pro-api/1.0.0.tar')->assertOk();
});

it('accepts an instance token of a licensed organization', function () {
    $extension = paidExtension();
    $organization = Organization::factory()->create();
    grantLicence($extension, $organization);

    $plain = Token::PREFIX_INSTANCE.Str::random(40);
    $instance = Instance::factory()->create(['organization_uuid' => $organization->uuid, 'token_hash' => Token::hash($plain)]);

    $this->withHeader('Authorization', 'Bearer '.$plain)->get('/npm/@acme%2fpro-engine')->assertOk();

    $instance->forceFill(['status' => 'revoked'])->save();
    $this->withHeader('Authorization', 'Bearer '.$plain)->get('/npm/@acme%2fpro-engine')->assertUnauthorized();
});

it('keeps paid packages listed in packages.json', function () {
    paidExtension();

    $this->get('/composer/packages.json')->assertOk()->assertJsonPath('available-packages', ['acme/pro-api']);
});
