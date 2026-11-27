<?php

use App\Models\Event;
use App\Models\Extension;
use App\Models\Token;

/*
 * Staff feature published listings from the review console (PUT /v1/admin/extensions/{slug}/featured);
 * the catalog exposes `featured_at` / `is_featured`, filters on `featured=1` and sorts with `sort=featured`.
 */

function featuredListing(array $attributes = []): Extension
{
    return Extension::factory()->published()->withPackages()->free()->withPublishedRelease('1.0.0')->create($attributes);
}

it('lets staff feature a published listing and stop featuring it', function () {
    $extension = featuredListing(['slug' => 'acme-tracker']);
    actingAsAccount(staffAccount(), [Token::SCOPE_REVIEW]);

    $this->putJson('/v1/admin/extensions/acme-tracker/featured', ['featured' => true])
        ->assertOk()
        ->assertJsonPath('is_featured', true);
    $featuredAt = $extension->fresh()->featured_at;
    expect($featuredAt)->not->toBeNull()
        ->and(Event::where('kind', 'extension.featured')->count())->toBe(1);

    // Featuring again keeps the date and records nothing new.
    $this->travel(5)->minutes();
    $this->putJson('/v1/admin/extensions/acme-tracker/featured', ['featured' => true])->assertOk();
    expect($extension->fresh()->featured_at->equalTo($featuredAt))->toBeTrue()
        ->and(Event::where('kind', 'extension.featured')->count())->toBe(1);

    $this->putJson('/v1/admin/extensions/'.$extension->public_id.'/featured', ['featured' => false])
        ->assertOk()
        ->assertJsonPath('is_featured', false)
        ->assertJsonPath('featured_at', null);
    expect(Event::where('kind', 'extension.unfeatured')->count())->toBe(1);
});

it('features only published listings and validates the body', function () {
    Extension::factory()->create(['slug' => 'draft-ext', 'status' => Extension::STATUS_DRAFT]);
    actingAsAccount(staffAccount(), [Token::SCOPE_REVIEW]);

    $this->putJson('/v1/admin/extensions/draft-ext/featured', ['featured' => true])
        ->assertStatus(422)->assertJsonValidationErrors('featured', 'fields');
    $this->putJson('/v1/admin/extensions/draft-ext/featured', [])
        ->assertStatus(422)->assertJsonValidationErrors('featured', 'fields');
    // Stopping always works.
    $this->putJson('/v1/admin/extensions/draft-ext/featured', ['featured' => false])->assertOk();
    $this->putJson('/v1/admin/extensions/missing/featured', ['featured' => true])->assertNotFound();
});

it('keeps featuring to staff', function () {
    [$account, , $extension] = publisher();
    $extension->update(['status' => Extension::STATUS_PUBLISHED]);
    actingAsAccount($account, [Token::SCOPE_ALL]);

    $this->putJson('/v1/admin/extensions/'.$extension->slug.'/featured', ['featured' => true])->assertForbidden();
    expect($extension->fresh()->featured_at)->toBeNull();
});

it('filters and sorts the catalog by featured', function () {
    $plain = featuredListing(['slug' => 'plain', 'name' => 'Plain']);
    $older = featuredListing(['slug' => 'older', 'name' => 'Older', 'featured_at' => now()->subDays(3)]);
    $newer = featuredListing(['slug' => 'newer', 'name' => 'Newer', 'featured_at' => now()->subDay()]);

    $this->getJson('/v1/catalog/extensions?featured=1')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.*.slug', fn ($slugs) => collect($slugs)->sort()->values()->all() === ['newer', 'older'])
        ->assertJsonPath('data.0.is_featured', true);
    $this->getJson('/v1/catalog/extensions?featured=0')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'plain')->assertJsonPath('data.0.is_featured', false)->assertJsonPath('data.0.featured_at', null);

    $this->getJson('/v1/catalog/extensions?sort=featured')
        ->assertOk()
        ->assertJsonPath('data.*.slug', ['newer', 'older', 'plain']);

    expect($plain->fresh()->isFeatured())->toBeFalse()->and($older->fresh()->isFeatured())->toBeTrue()->and($newer->fresh()->isFeatured())->toBeTrue();
});
