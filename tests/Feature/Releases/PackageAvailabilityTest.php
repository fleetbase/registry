<?php

use App\Models\Membership;
use App\Models\Organization;
use App\Models\Token;

function availability(Organization $organization, array $names)
{
    return test()->getJson('/v1/publisher/packages/availability?'.http_build_query(['organization' => $organization->slug] + $names));
}

it('says a name in the organization’s own scope is available', function () {
    [$account, $organization] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    availability($organization, ['npm' => " @{$organization->slug}/New-Engine ", 'composer' => "{$organization->slug}/new-api"])
        ->assertOk()
        ->assertExactJson([
            'npm' => ['name' => "@{$organization->slug}/new-engine", 'available' => true, 'reason' => null],
            'composer' => ['name' => "{$organization->slug}/new-api", 'available' => true, 'reason' => null],
        ]);

    // Only the names asked about; unscoped npm names are allowed.
    availability($organization, ['npm' => 'route-optimizer-engine'])->assertOk()
        ->assertExactJson(['npm' => ['name' => 'route-optimizer-engine', 'available' => true, 'reason' => null]]);
});

it('applies the format rules', function () {
    [$account, $organization] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    availability($organization, ['npm' => 'Not A Name!', 'composer' => 'novendor'])->assertOk()
        ->assertJsonPath('npm.available', false)
        ->assertJsonPath('npm.reason', 'Use a valid npm package name: lowercase letters, digits, - . _ ~, optionally scoped (@scope/name).')
        ->assertJsonPath('composer.available', false)
        ->assertJsonPath('composer.reason', 'Use a valid Composer package name: vendor/package in lowercase letters, digits, - . _.');

    availability($organization, ['npm' => '@'.$organization->slug.'/'.str_repeat('a', 220)])->assertOk()
        ->assertJsonPath('npm.reason', 'Package names are at most 214 characters.');
});

it('reserves the fleetbase namespaces for staff organizations', function () {
    [$account, $organization] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    availability($organization, ['npm' => '@fleetbase/route-engine', 'composer' => 'fleetbase/route-api'])->assertOk()
        ->assertJsonPath('npm.reason', "The '@fleetbase' package namespace is reserved for Fleetbase.")
        ->assertJsonPath('composer.reason', "The 'fleetbase' package namespace is reserved for Fleetbase.");

    $organization->forceFill(['is_staff' => true])->save();
    availability($organization, ['npm' => '@fleetbase/route-engine'])->assertOk()->assertJsonPath('npm.available', true);
});

it('applies the organization’s namespace rules', function () {
    [$account, $organization] = publisher();
    [, $other] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    availability($organization, ['npm' => "@{$other->slug}/new-engine", 'composer' => 'someone/new-api'])->assertOk()
        ->assertJsonPath('npm.reason', "The '@{$other->slug}' namespace belongs to another publisher.")
        ->assertJsonPath('composer.reason', "New package namespaces must match your organization slug ({$organization->slug}/…); ask Fleetbase staff to grant 'someone'.");

    $organization->forceFill(['meta' => ['namespaces' => ['someone']]])->save();
    availability($organization, ['composer' => 'someone/new-api'])->assertOk()->assertJsonPath('composer.available', true);
});

it('says names already in the packages table are taken', function () {
    [$account, $organization, $mine] = publisher();
    [, , $theirs] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    availability($organization, ['npm' => packageName($theirs, 'npm')])->assertOk()
        ->assertJsonPath('npm.available', false)
        ->assertJsonPath('npm.reason', 'This name is registered to another publisher\'s extension.');

    availability($organization, ['composer' => packageName($mine, 'composer')])->assertOk()
        ->assertJsonPath('composer.available', false)
        ->assertJsonPath('composer.reason', "Your extension {$mine->name} already uses this name.");

    // A listing of another of the caller's organizations.
    $second = Organization::factory()->create();
    $account->organizations()->attach($second->uuid, ['role' => Membership::ROLE_PUBLISHER]);
    availability($second, ['composer' => packageName($mine, 'composer')])->assertOk()
        ->assertJsonPath('composer.reason', "This name belongs to {$mine->slug}, which another of your organizations owns.");

    $mine->delete();
    availability($organization, ['composer' => packageName($mine, 'composer')])->assertOk()
        ->assertJsonPath('composer.reason', 'This name belongs to a deleted extension; contact Fleetbase staff.');
});

it('agrees with creating a listing', function () {
    [$account, $organization] = publisher();
    [, $other, $theirs] = publisher();
    $slug = $organization->slug;
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    $names = [
        "@{$slug}/fresh-engine",
        'unscoped-fresh-engine',
        'Not A Name!',
        '@fleetbase/fresh-engine',
        "@{$other->slug}/fresh-engine",
        '@someone/fresh-engine',
        packageName($theirs, 'npm'),
    ];

    foreach ($names as $index => $name) {
        $check = availability($organization, ['npm' => $name])->assertOk();
        $created = $this->postJson('/v1/publisher/extensions', [
            'name' => "Agreement {$index}",
            'organization' => $slug,
            'packages' => ['npm' => $name],
        ]);

        expect($created->status() === 201)->toBe($check->json('npm.available'), "{$name}: availability and create disagree");
        if ($created->status() === 422) {
            expect($created->json('fields')['packages.npm'][0])->toBe($check->json('npm.reason'));
        }
    }

    // The name it just claimed is now taken.
    availability($organization, ['npm' => "@{$slug}/fresh-engine"])->assertOk()->assertJsonPath('npm.available', false);
});

it('needs a name, membership and the publish scope', function () {
    [$account, $organization] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    availability($organization, [])->assertStatus(422)->assertJsonValidationErrors(['npm', 'composer'], 'fields');
    availability(Organization::factory()->create(), ['npm' => 'x-engine'])->assertStatus(422)->assertJsonPath('errors.0', 'You are not a member of that organization.');

    [$billing, $billingOrg] = publisher(Membership::ROLE_BILLING);
    actingAsAccount($billing, [Token::SCOPE_PUBLISH]);
    availability($billingOrg, ['npm' => 'x-engine'])->assertForbidden();

    actingAsAccount($account, [Token::SCOPE_READ]);
    availability($organization, ['npm' => 'x-engine'])->assertForbidden();
});
