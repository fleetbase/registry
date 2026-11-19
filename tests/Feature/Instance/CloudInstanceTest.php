<?php

use App\Models\Event;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\LicenceActivation;
use App\Models\Organization;
use App\Models\Release;
use App\Support\PlatformSignature;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config(['registry.cloud.platform_secret' => CLOUD_PLATFORM_SECRET, 'registry.cloud.organization' => 'fleetbase']);
});

/**
 * A published free extension with one published release.
 *
 * @return array{0: Extension, 1: Release}
 */
function cloudFreeListing(): array
{
    $extension = Extension::factory()->published()->free()->create();
    $release = Release::factory()->create(['extension_uuid' => $extension->uuid, 'version' => '1.0.0', 'status' => Release::STATUS_PUBLISHED, 'published_at' => now()]);

    return [$extension, $release];
}

it('registers the cloud instance with a request signed by the platform secret', function () {
    $response = registerCloudInstance()->assertCreated()
        ->assertJsonPath('rotated', false)
        ->assertJsonPath('instance.kind', 'cloud')
        ->assertJsonPath('instance.host', 'https://api.fleetbase.test')
        ->assertJsonPath('instance.organization.slug', 'fleetbase');

    $instance = Instance::sole();
    expect($instance->isPlatform())->toBeTrue()
        ->and($instance->name)->toBe('Fleetbase Cloud')
        ->and($instance->console_version)->toBe('0.8.0')
        ->and($instance->registered_by_account_uuid)->toBeNull()
        ->and(Event::where('kind', 'instance.registered')->sole()->payload)->toBe(['platform' => true]);

    $this->withHeader('Authorization', 'Bearer '.$response->json('token'))->getJson('/v1/instances/me')
        ->assertOk()->assertJsonPath('instance.id', $instance->public_id);
});

it('refuses cloud registration without a valid platform signature', function (TestResponse $response, int $status, string $message) {
    expect($response->assertStatus($status)->json('errors.0'))->toBe($message)
        ->and(Instance::count())->toBe(0);
})->with([
    'no secret configured' => [function () {
        config(['registry.cloud.platform_secret' => null]);

        return registerCloudInstance();
    }, 403, 'Cloud registration is not enabled on this registry.'],
    'unsigned' => [fn () => registerCloudInstance([], null), 401, 'The request is not signed with the platform secret.'],
    'wrong secret' => [fn () => registerCloudInstance([], 'another-secret-another-secret-another'), 401, 'The request is not signed with the platform secret.'],
    'expired' => [fn () => registerCloudInstance([], null, PlatformSignature::headers(CLOUD_PLATFORM_SECRET, json_encode(cloudRegistrationBody()), time() - 600)), 401, 'The signed request has expired. Check the clock on the calling server.'],
    'body changed after signing' => [fn () => registerCloudInstance(['host' => 'https://evil.test'], null, PlatformSignature::headers(CLOUD_PLATFORM_SECRET, json_encode(cloudRegistrationBody()))), 401, 'The request is not signed with the platform secret.'],
]);

it('refuses a replayed signed request', function () {
    $body = cloudRegistrationBody();
    $headers = PlatformSignature::headers(CLOUD_PLATFORM_SECRET, json_encode($body));

    $this->withHeaders($headers)->postJson('/v1/instances/register/cloud', $body)->assertCreated();
    $this->withHeaders($headers)->postJson('/v1/instances/register/cloud', $body)
        ->assertUnauthorized()->assertJsonPath('errors.0', 'This signed request was already used.');

    expect(Instance::count())->toBe(1);
});

it('checks the signature before the body and requires the host', function () {
    registerCloudInstance(['host' => null], null)->assertUnauthorized();
    registerCloudInstance(['host' => null])->assertUnprocessable();
});

it('is idempotent: the same organization and host rotates the one cloud instance (D17)', function () {
    $first = registerCloudInstance()->assertCreated();
    $instance = Instance::sole();

    // A restart without the stored token (another replica, a cleared cache): matched by host.
    $second = registerCloudInstance(['host' => 'HTTPS://API.fleetbase.test/', 'console_version' => '0.8.1'])->assertOk()
        ->assertJsonPath('rotated', true)
        ->assertJsonPath('instance.id', $instance->public_id);

    // With its current token: matched by token even from another host.
    registerCloudInstance(['host' => 'https://api-2.fleetbase.test', 'previous_token' => $second->json('token')])->assertOk()
        ->assertJsonPath('instance.id', $instance->public_id);

    expect(Instance::count())->toBe(1)
        ->and($instance->fresh()->console_version)->toBe('0.8.0')
        ->and(Instance::findByToken($first->json('token')))->toBeNull()
        ->and(Instance::findByToken($second->json('token')))->toBeNull()
        ->and(Event::where('kind', 'instance.reregistered')->orderBy('id')->get()->pluck('payload.matched_by')->all())->toBe(['host', 'token']);
});

it('keeps installs and seats when the cloud instance re-registers', function () {
    $token = registerCloudInstance()->assertCreated()->json('token');
    [$extension, $release] = cloudFreeListing();
    $company = cloudCompany();

    actingForCompany($company);
    $this->withHeader('Authorization', 'Bearer '.$token)
        ->postJson('/v1/instances/me/installs', ['extension' => $extension->public_id, 'version' => '1.0.0', 'status' => 'installed'])->assertCreated();

    $rotated = registerCloudInstance()->assertOk()->json('token');

    $this->withHeader('Authorization', 'Bearer '.$rotated)->getJson('/v1/instances/me/updates')
        ->assertOk()->assertJsonPath('data.0.extension.id', $extension->public_id);
    expect(LicenceActivation::whereNull('deactivated_at')->count())->toBe(1);
});

it('creates a separate instance for another host, as another cloud deployment', function () {
    registerCloudInstance()->assertCreated();
    registerCloudInstance(['host' => 'https://api.staging.fleetbase.test'])->assertCreated()->assertJsonPath('rotated', false);

    expect(Instance::count())->toBe(2)
        ->and(Instance::all()->every(fn (Instance $i) => $i->isPlatform()))->toBeTrue();
});

it('adopts the placeholder the importer left, whatever its host', function () {
    $placeholder = cloudPlatformInstance(['host' => null, 'token_hash' => null, 'meta' => ['platform' => true, 'source' => 'import']]);

    registerCloudInstance()->assertOk()
        ->assertJsonPath('rotated', true)
        ->assertJsonPath('instance.id', $placeholder->public_id)
        ->assertJsonPath('instance.host', 'https://api.fleetbase.test');

    expect($placeholder->fresh()->meta)->toBe(['platform' => true, 'source' => 'import'])
        ->and(Event::where('kind', 'instance.reregistered')->sole()->payload['matched_by'])->toBe('placeholder');
});

it('never rotates a self-hosted instance or another organization through the cloud endpoint', function () {
    $selfHosted = Instance::factory()->create(['host' => 'https://api.fleetbase.test']);
    $token = $selfHosted->issueToken();

    registerCloudInstance(['previous_token' => $token])->assertCreated();

    expect(Instance::findByToken($token)?->is($selfHosted))->toBeTrue()
        ->and(Instance::count())->toBe(2);
});

it('acts for the cloud company named in X-Registry-Tenant', function () {
    $instance = cloudPlatformInstance();
    actingAsInstance($instance);
    [$extension] = cloudFreeListing();
    $acme = cloudCompany('Acme');
    $licence = Licence::create(['extension_uuid' => $extension->uuid, 'organization_uuid' => $acme->uuid, 'kind' => Licence::KIND_PAID, 'max_activations' => 5, 'status' => Licence::STATUS_ACTIVE]);
    Licence::create(['extension_uuid' => $extension->uuid, 'organization_uuid' => $instance->organization_uuid, 'kind' => Licence::KIND_FREE, 'status' => Licence::STATUS_ACTIVE]);

    actingForCompany($acme);
    $this->getJson('/v1/instances/me')->assertOk()
        ->assertJsonPath('instance.organization.slug', 'fleetbase')
        ->assertJsonPath('tenant.id', $acme->public_id)
        ->assertJsonCount(1, 'licences')
        ->assertJsonPath('licences.0.id', $licence->public_id);
});

it('creates the organization of a cloud company seen for the first time', function () {
    actingAsInstance(cloudPlatformInstance());
    $company = '7f0c2c3e-0d1b-4f43-9a7e-3b1f9d0c1a22';

    actingForCompany($company, 'Globex Logística');
    $this->getJson('/v1/instances/me')->assertOk()->assertJsonPath('tenant.name', 'Globex Logística');
    $this->getJson('/v1/instances/me')->assertOk();

    $organization = Organization::where('source_uuid', $company)->sole();
    expect($organization->slug)->toBe('globex-logistica')
        ->and($organization->is_personal)->toBeFalse()
        ->and($organization->meta)->toBe(['source' => 'cloud', 'company_uuid' => $company]);

    actingForCompany('not-a-uuid');
    $this->getJson('/v1/instances/me')->assertUnprocessable();
});

it('ignores X-Registry-Tenant from any instance but the cloud one', function () {
    $organization = Organization::factory()->create();
    actingAsInstance(Instance::factory()->create(['organization_uuid' => $organization->uuid, 'kind' => Instance::KIND_CLOUD]));

    actingForCompany(cloudCompany());
    $this->getJson('/v1/instances/me')->assertOk()->assertJsonMissingPath('tenant');
    expect(Organization::count())->toBe(2);
});

it('keeps one install and one seat per company on the cloud instance', function () {
    $instance = cloudPlatformInstance();
    actingAsInstance($instance);
    [$extension] = cloudFreeListing();
    [$acme, $globex] = [cloudCompany('Acme'), cloudCompany('Globex')];

    foreach ([$acme, $globex] as $company) {
        actingForCompany($company);
        $this->postJson('/v1/instances/me/installs', ['extension' => $extension->public_id, 'version' => '1.0.0', 'status' => 'installing'])->assertCreated();
        $this->postJson('/v1/instances/me/installs', ['extension' => $extension->public_id, 'version' => '1.0.0', 'status' => 'installed'])->assertOk();
    }

    $installs = Install::where('instance_uuid', $instance->uuid)->where('status', Install::STATUS_INSTALLED)->get();
    expect($installs->pluck('organization_uuid')->sort()->values()->all())->toBe(collect([$acme->uuid, $globex->uuid])->sort()->values()->all())
        ->and(Licence::where('extension_uuid', $extension->uuid)->pluck('organization_uuid')->sort()->values()->all())->toBe(collect([$acme->uuid, $globex->uuid])->sort()->values()->all())
        ->and(LicenceActivation::where('instance_uuid', $instance->uuid)->whereNull('deactivated_at')->count())->toBe(2);

    // Acme uninstalls: Globex keeps its install and seat.
    actingForCompany($acme);
    $this->deleteJson('/v1/instances/me/installs/'.$extension->public_id)->assertOk()->assertJsonPath('deactivated', 1);
    $this->getJson('/v1/instances/me/updates')->assertOk()->assertJsonCount(0, 'data');

    actingForCompany($globex);
    $this->getJson('/v1/instances/me/updates')->assertOk()->assertJsonCount(1, 'data');
    expect(LicenceActivation::whereNull('deactivated_at')->sole()->licence->organization_uuid)->toBe($globex->uuid);
});

it('refuses a paid install for a company without a licence', function () {
    actingAsInstance(cloudPlatformInstance());
    $extension = Extension::factory()->published()->paid()->create();
    Release::factory()->create(['extension_uuid' => $extension->uuid, 'version' => '1.0.0', 'status' => Release::STATUS_PUBLISHED, 'published_at' => now()]);
    $buyer = cloudCompany();
    Licence::create(['extension_uuid' => $extension->uuid, 'organization_uuid' => $buyer->uuid, 'kind' => Licence::KIND_PAID, 'max_activations' => 5, 'status' => Licence::STATUS_ACTIVE]);

    actingForCompany(cloudCompany());
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->public_id, 'version' => '1.0.0', 'status' => 'installing'])->assertStatus(403);

    actingForCompany($buyer);
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->public_id, 'version' => '1.0.0', 'status' => 'installing'])->assertCreated();
});

it('lists installs per company for the cloud instance only', function () {
    $instance = cloudPlatformInstance();
    [$extension, $release] = cloudFreeListing();
    $extension->packages()->create(['kind' => 'npm', 'name' => '@acme/thing-engine']);
    $acme = cloudCompany('Acme');
    Install::factory()->create(['instance_uuid' => $instance->uuid, 'organization_uuid' => $acme->uuid, 'extension_uuid' => $extension->uuid, 'release_uuid' => $release->uuid]);
    Install::factory()->uninstalled()->create(['instance_uuid' => $instance->uuid, 'organization_uuid' => cloudCompany()->uuid, 'extension_uuid' => $extension->uuid]);

    actingAsInstance($instance);
    $this->getJson('/v1/instances/me/tenant-installs')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.company_uuid', $acme->source_uuid)
        ->assertJsonPath('data.0.extension.id', $extension->public_id)
        ->assertJsonPath('data.0.packages', ['npm' => '@acme/thing-engine'])
        ->assertJsonPath('data.0.release.version', '1.0.0')
        ->assertJsonPath('meta.total', 1);
    $this->getJson('/v1/instances/me/tenant-installs?per_page=0&page=2')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.last_page', 1);

    actingAsInstance(Instance::factory()->create());
    $this->getJson('/v1/instances/me/tenant-installs')->assertForbidden();
});

it('records installs for the organization of a self-hosted instance', function () {
    $instance = Instance::factory()->create();
    actingAsInstance($instance);
    [$extension] = cloudFreeListing();

    actingForCompany(cloudCompany());
    $this->postJson('/v1/instances/me/installs', ['extension' => $extension->public_id, 'version' => '1.0.0', 'status' => 'installed'])->assertCreated();

    expect(Install::sole()->organization_uuid)->toBe($instance->organization_uuid);
});
