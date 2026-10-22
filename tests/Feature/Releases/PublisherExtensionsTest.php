<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Extension;
use App\Models\Membership;
use App\Models\Organization;
use App\Models\Price;
use App\Models\Token;
use Illuminate\Support\Str;

/**
 * An account owning a fresh organization, acting with a publish token.
 *
 * @return array{0: Account, 1: Organization}
 */
function publishingAccount(string $role = Membership::ROLE_OWNER, array $scopes = [Token::SCOPE_PUBLISH]): array
{
    $account = Account::factory()->create();
    $organization = Organization::factory()->create(['slug' => 'acme-'.strtolower(Str::random(5))]);
    $account->organizations()->attach($organization->uuid, ['role' => $role]);
    actingAsAccount($account, $scopes);

    return [$account, $organization];
}

function listingPayload(Organization $organization, array $overrides = []): array
{
    return array_replace_recursive([
        'name' => 'Route Optimizer',
        'organization' => $organization->slug,
        'packages' => ['npm' => '@'.$organization->slug.'/route-optimizer-engine', 'composer' => $organization->slug.'/route-optimizer-api'],
    ], $overrides);
}

it('creates a draft listing with its package names', function () {
    [$account, $organization] = publishingAccount();
    $category = Category::factory()->create(['slug' => 'routing']);

    $response = $this->postJson('/v1/publisher/extensions', listingPayload($organization, [
        'subtitle' => 'Faster routes',
        'category' => 'routing',
        'tags' => ['routing'],
        'website_url' => 'https://acme.test',
    ]))->assertCreated();

    $response->assertJsonPath('slug', 'route-optimizer')
        ->assertJsonPath('status', Extension::STATUS_DRAFT)
        ->assertJsonPath('organization.slug', $organization->slug)
        ->assertJsonPath('category.slug', 'routing')
        ->assertJsonPath('price.model', Price::MODEL_FREE)
        ->assertJsonPath('packages', [
            ['kind' => 'npm', 'name' => '@'.$organization->slug.'/route-optimizer-engine'],
            ['kind' => 'composer', 'name' => $organization->slug.'/route-optimizer-api'],
        ])
        ->assertJsonPath('readiness.ready', false);

    expect($response->json('readiness.missing'))->toContain('The listing needs promotional text.');

    $extension = Extension::where('slug', 'route-optimizer')->first();
    expect($extension->created_by_account_uuid)->toBe($account->uuid)
        ->and($extension->category_uuid)->toBe($category->uuid);
    $this->assertDatabaseHas('events', ['kind' => 'extension.created', 'subject_uuid' => $extension->uuid]);
});

it('is idempotent on package names', function () {
    [, $organization] = publishingAccount();

    $first = $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['packages' => ['composer' => null]]))->assertCreated();
    $again = $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['name' => 'Renamed', 'packages' => ['composer' => null]]))->assertOk();
    expect($again->json('id'))->toBe($first->json('id'))
        ->and($again->json('name'))->toBe('Route Optimizer');

    // A kind the listing lacks is added.
    $this->postJson('/v1/publisher/extensions', listingPayload($organization))->assertOk()
        ->assertJsonPath('id', $first->json('id'))
        ->assertJsonCount(2, 'packages');

    // A different name for a kind the listing has is a rename: refused.
    $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['packages' => ['composer' => $organization->slug.'/other-api']]))
        ->assertStatus(409);

    expect(Extension::count())->toBe(1);
});

it('refuses package names and slugs that belong to another publisher', function () {
    [, $mine] = publishingAccount();
    $this->postJson('/v1/publisher/extensions', listingPayload($mine))->assertCreated();

    [, $theirs] = publishingAccount();
    $this->postJson('/v1/publisher/extensions', listingPayload($theirs, ['packages' => ['npm' => '@'.$mine->slug.'/route-optimizer-engine', 'composer' => null]]))
        ->assertStatus(409)->assertJsonPath('errors.0', 'These package names are registered to another publisher\'s extension.');

    $this->postJson('/v1/publisher/extensions', listingPayload($theirs))
        ->assertStatus(422)->assertJsonPath('fields.slug.0', 'The slug route-optimizer is taken.');

    // Another name in someone else's scope.
    $this->postJson('/v1/publisher/extensions', listingPayload($theirs, ['slug' => 'other', 'packages' => ['npm' => '@'.$mine->slug.'/other-engine', 'composer' => null]]))
        ->assertStatus(422)->assertJson(['fields' => ['packages.npm' => ["The '@{$mine->slug}' namespace belongs to another publisher."]]]);
});

it('applies the namespace rules', function () {
    [, $organization] = publishingAccount();

    $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['packages' => ['npm' => '@fleetbase/route-engine', 'composer' => null]]))
        ->assertStatus(422)->assertJson(['fields' => ['packages.npm' => ["The '@fleetbase' package namespace is reserved for Fleetbase."]]]);

    $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['packages' => ['npm' => '@someone/route-engine', 'composer' => null]]))
        ->assertStatus(422)->assertJson(['fields' => ['packages.npm' => ["New package namespaces must match your organization slug (@{$organization->slug}/…); ask Fleetbase staff to grant '@someone'."]]]);

    // Unscoped npm names are allowed; a granted namespace is too.
    $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['slug' => 'unscoped', 'packages' => ['npm' => 'route-optimizer-engine', 'composer' => null]]))->assertCreated();
    $organization->forceFill(['meta' => ['namespaces' => ['someone']]])->save();
    $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['slug' => 'granted', 'packages' => ['npm' => '@someone/route-engine', 'composer' => 'someone/route-api']]))->assertCreated();

    // Staff organizations may use @fleetbase.
    [, $staff] = publishingAccount();
    $staff->forceFill(['is_staff' => true])->save();
    $this->postJson('/v1/publisher/extensions', listingPayload($staff, ['slug' => 'fleet-thing', 'packages' => ['npm' => '@fleetbase/thing-engine', 'composer' => 'fleetbase/thing-api']]))->assertCreated();
});

it('validates package names and needs at least one', function () {
    [, $organization] = publishingAccount();

    $this->postJson('/v1/publisher/extensions', ['name' => 'X y z', 'packages' => []])
        ->assertStatus(422)->assertJsonValidationErrors(['packages'], 'fields');
    $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['packages' => ['npm' => 'Not A Name!', 'composer' => 'novendor']]))
        ->assertStatus(422)->assertJsonValidationErrors(['packages.npm', 'packages.composer'], 'fields');
});

it('needs a publishing role and the publish scope', function () {
    [, $organization] = publishingAccount(Membership::ROLE_BILLING);
    $this->postJson('/v1/publisher/extensions', listingPayload($organization))
        ->assertForbidden()->assertJsonPath('errors.0', 'Only organization owners, admins and publishers can publish extensions.');

    [, $organization] = publishingAccount(Membership::ROLE_OWNER, [Token::SCOPE_READ]);
    $this->postJson('/v1/publisher/extensions', listingPayload($organization))->assertForbidden();

    [$account, $organization] = publishingAccount();
    $this->postJson('/v1/publisher/extensions', listingPayload($organization, ['organization' => Organization::factory()->create()->slug]))
        ->assertStatus(422)->assertJsonPath('errors.0', 'You are not a member of that organization.');

    // A token pinned to one organization cannot publish for another.
    $other = Organization::factory()->create();
    $account->organizations()->attach($other->uuid, ['role' => Membership::ROLE_OWNER]);
    $pinned = Token::issue($account, 'pinned', [Token::SCOPE_PUBLISH], null, $organization->uuid);
    $this->withHeader('Authorization', 'Bearer '.$pinned->plainTextToken)
        ->postJson('/v1/publisher/extensions', listingPayload($other))
        ->assertForbidden()->assertJsonPath('errors.0', 'This token is limited to another organization.');
});

it('lists, shows and edits the caller\'s listings only', function () {
    [$account, $organization, $extension] = publisher(extension: ['status' => 'published']);
    [, , $foreign] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    $this->getJson('/v1/publisher/extensions')->assertOk()->assertJsonCount(1)->assertJsonPath('0.slug', $extension->slug);
    $this->getJson('/v1/publisher/extensions/'.$extension->public_id)->assertOk()->assertJsonPath('readiness.ready', true)->assertJsonPath('releases', []);
    $this->getJson('/v1/publisher/extensions/'.$foreign->slug)->assertNotFound();

    $category = Category::factory()->create();
    $this->patchJson('/v1/publisher/extensions/'.$extension->slug, [
        'subtitle' => 'New subtitle',
        'category' => $category->slug,
        'tags' => ['a', 'b'],
        'support_url' => 'not a url',
    ])->assertStatus(422)->assertJsonValidationErrors('support_url', 'fields');

    // Prime the catalog cache, edit, and see the edit straight away.
    $this->getJson('/v1/catalog/extensions/'.$extension->slug)->assertOk();
    $this->patchJson('/v1/publisher/extensions/'.$extension->slug, ['subtitle' => 'New subtitle', 'category' => $category->slug, 'tags' => ['a', 'b']])
        ->assertOk()->assertJsonPath('subtitle', 'New subtitle')->assertJsonPath('category.slug', $category->slug);
    $this->getJson('/v1/catalog/extensions/'.$extension->slug)->assertOk()->assertJsonPath('subtitle', 'New subtitle');

    $this->patchJson('/v1/publisher/extensions/'.$extension->slug, ['category' => 'nope'])->assertStatus(422);
    $this->patchJson('/v1/publisher/extensions/'.$foreign->slug, ['subtitle' => 'x'])->assertNotFound();
});
