<?php

use App\Exceptions\ReservedPackageNamespaceException;
use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Organization;
use Illuminate\Database\Eloquent\MassAssignmentException;

it('rejects reserved namespaces for non-staff organizations', function (string $kind, string $name) {
    $extension = Extension::factory()->create();

    expect(fn () => $extension->packages()->create(['kind' => $kind, 'name' => $name]))
        ->toThrow(ReservedPackageNamespaceException::class);

    expect(ExtensionPackage::count())->toBe(0);
})->with([
    'npm scope' => [ExtensionPackage::KIND_NPM, '@fleetbase/fleetops-engine'],
    'npm scope, mixed case' => [ExtensionPackage::KIND_NPM, '@FleetBase/fleetops-engine'],
    'composer vendor' => [ExtensionPackage::KIND_COMPOSER, 'fleetbase/fleetops-api'],
]);

it('lets the staff organization own reserved namespaces', function () {
    $extension = Extension::factory()->for(Organization::factory()->staff())->create();

    $npm = $extension->packages()->create(['kind' => ExtensionPackage::KIND_NPM, 'name' => '@Fleetbase/FleetOps-Engine']);
    $extension->packages()->create(['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => 'fleetbase/fleetops-api']);

    expect($npm->name)->toBe('@fleetbase/fleetops-engine')
        ->and(ExtensionPackage::count())->toBe(2);
});

it('leaves other namespaces and lookalikes alone', function (string $kind, string $name) {
    $extension = Extension::factory()->create();

    $extension->packages()->create(['kind' => $kind, 'name' => $name]);

    expect(ExtensionPackage::count())->toBe(1);
})->with([
    [ExtensionPackage::KIND_NPM, '@acme/fleetbase-engine'],
    [ExtensionPackage::KIND_NPM, '@fleetbase-community/engine'],
    [ExtensionPackage::KIND_NPM, 'fleetbase-unscoped-engine'],
    [ExtensionPackage::KIND_COMPOSER, 'fleetbaseplus/api'],
]);

it('also guards renames and moves to another listing', function () {
    $staffExtension = Extension::factory()->for(Organization::factory()->staff())->create();
    $package = $staffExtension->packages()->create(['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => 'fleetbase/storefront-api']);
    $other = Extension::factory()->create();

    expect(fn () => $package->update(['extension_uuid' => $other->uuid]))->toThrow(ReservedPackageNamespaceException::class);

    $mine = $other->packages()->create(['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => 'acme/storefront-api']);
    expect(fn () => $mine->update(['name' => 'fleetbase/storefront-pro-api']))->toThrow(ReservedPackageNamespaceException::class);
    expect($mine->fresh()->name)->toBe('acme/storefront-api');
});

it('renders as a 422 on the name field', function () {
    $extension = Extension::factory()->create();
    Route::post('/v1/_namespace-test', fn () => $extension->packages()->create(['kind' => 'npm', 'name' => '@fleetbase/x']));

    $this->postJson('/v1/_namespace-test')->assertStatus(422)
        ->assertJsonPath('fields.name.0', fn (string $m) => str_contains($m, "'@fleetbase' package namespace is reserved"));
});

it('keeps is_staff out of mass assignment and sets it with registry:staff', function () {
    $organization = Organization::factory()->create(['slug' => 'fleetbase']);

    expect(fn () => Organization::create(['slug' => 'x', 'name' => 'X', 'is_staff' => true]))
        ->toThrow(MassAssignmentException::class);

    $this->artisan('registry:staff', ['organization' => 'fleetbase'])->assertSuccessful();
    expect($organization->fresh()->isStaff())->toBeTrue();

    $this->artisan('registry:staff', ['organization' => $organization->public_id, '--revoke' => true])->assertSuccessful();
    expect($organization->fresh()->isStaff())->toBeFalse();

    $this->artisan('registry:staff', ['organization' => 'nope'])->assertFailed();
});
