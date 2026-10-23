<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\Organization;
use App\Models\Release;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;

function seedWebsite(): array
{
    expect(Artisan::call('registry:seed-website', ['--json' => true]))->toBe(0);

    return json_decode(Artisan::output(), true);
}

it('seeds the website demo and is safe to re-run', function () {
    $first = seedWebsite();
    $second = seedWebsite();

    expect($second)->toBe($first)
        ->and($first['staff'])->toBe(['email' => 'staff@registry.test', 'password' => 'password123'])
        ->and($first['publisher'])->toBe(['email' => 'publisher@registry.test', 'password' => 'password123'])
        ->and($first['extensions'])->toBe(['registry-demo-hello', 'registry-demo-pro', 'acme-fleet-insights', 'acme-route-planner'])
        ->and(Release::findById($first['queued_release'])->status)->toBe(Release::STATUS_SUBMITTED)
        ->and(Release::count())->toBe(3)
        ->and(Extension::where('slug', 'acme-route-planner')->sole()->status)->toBe(Extension::STATUS_DRAFT);

    $staff = Account::where('email', 'staff@registry.test')->sole();
    expect($staff->isStaff())->toBeTrue()
        ->and(Hash::check('password123', $staff->password))->toBeTrue()
        ->and(Account::where('email', 'publisher@registry.test')->sole()->roleIn(Organization::where('slug', 'acme')->sole()))->toBe('owner');

    // The catalog shows the free and the paid listing with their copy.
    $this->getJson('/v1/catalog/extensions?pricing=paid')->assertJsonPath('data.0.slug', 'registry-demo-pro')->assertJsonPath('data.0.category.slug', 'analytics-and-reporting');
    $this->getJson('/v1/catalog/extensions/registry-demo-hello')
        ->assertOk()
        ->assertJsonPath('is_paid', false)
        ->assertJsonPath('promotional_text', fn ($text) => str_contains($text, 'publisher website'));

    // The accounts sign in and see their work.
    actingAsAccount($staff);
    $this->getJson('/v1/admin/releases')->assertOk()->assertJsonPath('data.0.id', $first['queued_release']);
});

it('never runs in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('registry:seed-website')->assertFailed();
    expect(Account::count())->toBe(0);
});
