<?php

use App\Models\Category;
use App\Models\Extension;
use App\Models\Organization;
use Database\Seeders\CategorySeeder;

it('shows a publisher profile with their published extensions', function () {
    $publisher = Organization::factory()->verified()->create(['slug' => 'acme', 'name' => 'Acme Corp', 'website' => 'https://acme.test']);
    Extension::factory()->published()->free()->withPublishedRelease()->create(['organization_uuid' => $publisher->uuid, 'slug' => 'one']);
    Extension::factory()->published()->free()->withPublishedRelease()->create(['organization_uuid' => $publisher->uuid, 'slug' => 'two']);
    Extension::factory()->create(['organization_uuid' => $publisher->uuid, 'slug' => 'draft']);

    $response = $this->getJson('/v1/catalog/publishers/acme')->assertOk();

    $response->assertJsonPath('id', $publisher->public_id)
        ->assertJsonPath('name', 'Acme Corp')
        ->assertJsonPath('website', 'https://acme.test')
        ->assertJsonPath('is_verified', true)
        ->assertJsonPath('extensions_count', 2)
        ->assertJsonCount(2, 'extensions')
        ->assertJsonMissingPath('stripe_connect_id')
        ->assertJsonMissingPath('uuid');

    expect(collect($response->json('extensions'))->pluck('slug')->all())->not->toContain('draft');

    $this->getJson('/v1/catalog/publishers/'.$publisher->public_id)->assertOk();
    $this->getJson('/v1/catalog/publishers/nobody')->assertStatus(404);
});

it('lists categories in sort order with published extension counts', function () {
    $a = Category::factory()->create(['slug' => 'accounting', 'name' => 'Accounting', 'sort' => 2]);
    $t = Category::factory()->create(['slug' => 'telematics', 'name' => 'Telematics', 'sort' => 1]);
    Extension::factory()->published()->count(2)->create(['category_uuid' => $t->uuid]);
    Extension::factory()->create(['category_uuid' => $t->uuid]);

    $this->getJson('/v1/catalog/categories')
        ->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.slug', 'telematics')
        ->assertJsonPath('0.extensions_count', 2)
        ->assertJsonPath('1.slug', 'accounting')
        ->assertJsonPath('1.extensions_count', 0);

    expect($this->getJson('/v1/catalog/categories')->json('0.id'))->toStartWith('cat_');
});

it('seeds the 41 catalog categories from registry-bridge', function () {
    $this->seed(CategorySeeder::class);
    $this->seed(CategorySeeder::class);

    expect(Category::count())->toBe(41)
        ->and(Category::where('slug', 'telematics')->firstOrFail()->internal_id)->toBe('FLBTELEMATICS')
        ->and(Category::where('internal_id', 'FLBCRM')->firstOrFail()->slug)->toBe('customer-relationship-management');
});
