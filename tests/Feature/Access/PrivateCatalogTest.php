<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Extension;
use App\Models\Install;
use App\Models\Instance;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\Release;
use App\Models\Token;

/*
 * Private extensions in the catalog API, the website and the instance API: invisible (404,
 * absent from lists, search and counts) to callers without access, visible to the owner and to
 * granted organizations, never cached publicly.
 */

function catalogSlugs($response): array
{
    return collect($response->json('data'))->pluck('slug')->sort()->values()->all();
}

beforeEach(function () {
    $this->category = Category::factory()->create(['slug' => 'ops']);
    $this->private = privateExtension(attributes: ['category_uuid' => $this->category->uuid]);
    $this->public = makePackage('npm', '@acme/open-engine', extension: ['slug' => 'open-ext', 'name' => 'Open Workflows', 'category_uuid' => $this->category->uuid])->extension;
    Release::factory()->published()->create(['extension_uuid' => $this->public->uuid, 'version' => '1.0.0']);
});

it('leaves private extensions out of the anonymous catalog, search, detail, releases, publisher page and counts', function () {
    expect(catalogSlugs($this->getJson('/v1/catalog/extensions')))->toBe(['open-ext']);
    expect(catalogSlugs($this->getJson('/v1/catalog/extensions?q=Workflows')))->toBe(['open-ext']);
    expect(catalogSlugs($this->getJson('/v1/catalog/extensions?q=client-secret')))->toBe([]);
    expect(catalogSlugs($this->getJson('/v1/catalog/extensions?category=ops')))->toBe(['open-ext']);

    $this->getJson('/v1/catalog/extensions/client-secret')->assertNotFound();
    $this->getJson('/v1/catalog/extensions/'.$this->private->public_id)->assertNotFound();
    $this->getJson('/v1/catalog/extensions/client-secret/releases')->assertNotFound();

    $publisher = $this->getJson('/v1/catalog/publishers/'.$this->private->organization->slug)->assertOk();
    expect($publisher->json('extensions'))->toBe([]);
    expect(json_encode($publisher->json()))->not->toContain('client-secret');

    $categories = collect($this->getJson('/v1/catalog/categories')->json());
    expect($categories->firstWhere('slug', 'ops')['extensions_count'])->toBe(1);
});

it('leaves private extensions out for instances and tokens without access', function () {
    [, $stranger, $account] = clientOrganization('Stranger');

    expect(catalogSlugs($this->getJson('/v1/catalog/extensions', instanceHeaders($stranger))))->toBe(['open-ext']);
    $this->getJson('/v1/catalog/extensions/client-secret', instanceHeaders($stranger))->assertNotFound();
    $this->getJson('/v1/catalog/extensions/client-secret', personalHeaders($account, [Token::SCOPE_READ]))->assertNotFound();
});

it('shows a private extension to an instance of a granted organization, privately', function () {
    [$client, $instance] = clientOrganization();
    accessGrant($this->private, $client);
    $headers = instanceHeaders($instance);

    $list = $this->getJson('/v1/catalog/extensions', $headers)->assertOk();
    expect(catalogSlugs($list))->toBe(['client-secret', 'open-ext']);
    expect(collect($list->json('data'))->firstWhere('slug', 'client-secret')['visibility'])->toBe('private');
    $list->assertHeader('Cache-Control', 'no-cache, private');
    expect($list->headers->get('Vary'))->toContain('Authorization');

    $this->getJson('/v1/catalog/extensions/client-secret', $headers)->assertOk()->assertJsonPath('slug', 'client-secret')
        ->assertHeader('Cache-Control', 'no-cache, private');
    $this->getJson('/v1/catalog/extensions/client-secret/releases', $headers)->assertOk()->assertJsonPath('latest', '1.0.0');
    expect(collect($this->getJson('/v1/catalog/extensions?q=Secret', $headers)->json('data'))->pluck('slug')->all())->toContain('client-secret');
    expect(collect($this->getJson('/v1/catalog/categories', $headers)->json())->firstWhere('slug', 'ops')['extensions_count'])->toBe(2);
});

it('shows a private extension to its owner through a read token', function () {
    $owner = Account::factory()->create();
    $owner->organizations()->attach($this->private->organization_uuid, ['role' => Membership::ROLE_MEMBER]);

    $this->getJson('/v1/catalog/extensions/client-secret', personalHeaders($owner, [Token::SCOPE_READ]))->assertOk();
    // a publish-only token is no catalog credential
    $this->getJson('/v1/catalog/extensions/client-secret', personalHeaders($owner, [Token::SCOPE_PUBLISH]))->assertNotFound();
});

it('limits catalog visibility of an instance-limited grant to its instances', function () {
    [$client, $allowed] = clientOrganization();
    $other = Instance::factory()->for($client)->create();
    accessGrant($this->private, $client, ['instance_uuids' => [$allowed->uuid]]);

    $this->getJson('/v1/catalog/extensions/client-secret', instanceHeaders($allowed))->assertOk();
    $this->getJson('/v1/catalog/extensions/client-secret', instanceHeaders($other))->assertNotFound();
});

it('keeps the anonymous public cache free of private data, and keys private entries by access', function () {
    [$client, $instance] = clientOrganization();
    $grant = accessGrant($this->private, $client);

    // warm the cache as the granted instance first, then as anonymous
    expect(catalogSlugs($this->getJson('/v1/catalog/extensions', instanceHeaders($instance))))->toBe(['client-secret', 'open-ext']);
    $anonymous = $this->getJson('/v1/catalog/extensions');
    expect(catalogSlugs($anonymous))->toBe(['open-ext']);
    expect($anonymous->headers->get('Cache-Control'))->toContain('public');
    expect($anonymous->headers->get('Vary'))->toContain('Authorization');

    // revoking changes the access set, and so the cache key: no flush needed
    $grant->forceFill(['status' => Licence::STATUS_REVOKED])->save();
    expect(catalogSlugs($this->getJson('/v1/catalog/extensions', instanceHeaders($instance))))->toBe(['open-ext']);
    $this->getJson('/v1/catalog/extensions/client-secret', instanceHeaders($instance))->assertNotFound();
});

it('renders the website extension page only for members who may see it', function () {
    $this->get('/extensions/client-secret')->assertNotFound();
    $this->get('/extensions/open-ext')->assertOk();

    [$client, , $clientAccount] = clientOrganization();
    actingOnWebsite($clientAccount);
    $this->get('/extensions/client-secret')->assertNotFound();

    accessGrant($this->private, $client);
    $page = $this->get('/extensions/client-secret')->assertOk();
    expect($page->headers->get('Cache-Control'))->toContain('private')->not->toContain('public');
});

it('leaves private extensions off the website discover page', function () {
    $page = $this->get('/')->assertOk();

    expect($page->getContent())->not->toContain('client-secret')->toContain('open-ext');
});

it('refuses install reports, checkout and updates for private extensions without access', function () {
    [$client, $instance] = clientOrganization();
    $release = Release::query()->where('extension_uuid', $this->private->uuid)->firstOrFail();
    $report = ['extension' => 'client-secret', 'version' => '1.0.0', 'status' => Install::STATUS_INSTALLING];

    $this->postJson('/v1/instances/me/installs', $report, instanceHeaders($instance))->assertNotFound();
    $this->postJson('/v1/instances/me/installs', ['extension' => '@client/secret-engine'] + $report, instanceHeaders($instance))->assertNotFound();
    $this->postJson('/v1/instances/me/purchases/checkout', ['extension' => 'client-secret', 'return_url' => 'https://'.$instance->host.'/x'], instanceHeaders($instance))->assertNotFound();

    // with a grant the install claims a seat on the grant
    $grant = accessGrant($this->private, $client);
    $this->postJson('/v1/instances/me/installs', $report, instanceHeaders($instance))->assertCreated()
        ->assertJsonPath('activation.licence.id', $grant->public_id);
    $this->postJson('/v1/instances/me/installs', ['status' => Install::STATUS_INSTALLED] + $report, instanceHeaders($instance))->assertOk();
    expect(Licence::query()->where('organization_uuid', $client->uuid)->pluck('kind')->all())->toBe([Licence::KIND_GRANT]);

    $updates = $this->getJson('/v1/instances/me/updates', instanceHeaders($instance))->assertOk();
    expect($updates->json('data.0.access'))->toBeTrue();
    expect($updates->json('data.0.latest.version'))->toBe($release->version);

    // after revocation updates stop and a new install is refused
    $grant->forceFill(['status' => Licence::STATUS_REVOKED])->save();
    $updates = $this->getJson('/v1/instances/me/updates', instanceHeaders($instance))->assertOk();
    expect($updates->json('data.0.access'))->toBeFalse();
    expect($updates->json('data.0.latest'))->toBeNull();
    $this->postJson('/v1/instances/me/installs', $report, instanceHeaders($instance))->assertNotFound();
});

it('refuses seats on an instance-limited grant to other instances', function () {
    [$client, $allowed] = clientOrganization();
    $other = Instance::factory()->for($client)->create();
    accessGrant($this->private, $client, ['instance_uuids' => [$allowed->uuid]]);
    $report = ['extension' => 'client-secret', 'version' => '1.0.0', 'status' => Install::STATUS_INSTALLING];

    $this->postJson('/v1/instances/me/installs', $report, instanceHeaders($allowed))->assertCreated();
    $this->postJson('/v1/instances/me/installs', $report, instanceHeaders($other))->assertNotFound();
});

it('lets the owner install its own private extension with the automatic licence', function () {
    $instance = Instance::factory()->create(['organization_uuid' => $this->private->organization_uuid]);

    $this->postJson('/v1/instances/me/installs', ['extension' => 'client-secret', 'version' => '1.0.0', 'status' => Install::STATUS_INSTALLING], instanceHeaders($instance))
        ->assertCreated();
    expect(Extension::find($this->private->uuid)->licences()->value('kind'))->toBe(Licence::KIND_FREE);
});
