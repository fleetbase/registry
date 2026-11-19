<?php

use App\Releases\ExtensionManifest;

it('accepts a valid manifest', function () {
    expect(ExtensionManifest::validate([
        'name' => 'Pallet',
        'version' => '1.2.0-beta.1',
        'engine' => '@fleetbase/pallet-engine',
        'api' => 'fleetbase/pallet-api',
        'compat' => ['console' => '>=0.7 <1.0', 'api' => '^1.6'],
        'channel' => 'beta',
        'permissions' => ['custom field allowed'],
    ]))->toBe([]);
});

it('reports schema violations by JSON pointer', function () {
    $errors = ExtensionManifest::validate(['name' => 'X', 'version' => '1.2', 'channel' => 'nightly']);

    expect(implode("\n", $errors))->toContain('/version')->toContain('/channel')
        ->and(ExtensionManifest::validate(['name' => 'X', 'version' => '1.0.0']))->not->toBe([]); // neither engine nor api
});

it('decodes only JSON objects', function () {
    expect(ExtensionManifest::decode("\xEF\xBB\xBF{\"name\":\"x\"}"))->toBe(['name' => 'x'])
        ->and(ExtensionManifest::decode('[1,2]'))->toBeNull()
        ->and(ExtensionManifest::decode('nope'))->toBeNull();
});
