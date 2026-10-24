<?php

use App\Releases\Archives\ArchiveInspector;
use App\Releases\Secrets\SecretRules;

it('records raw names, types, link targets, hashes and binaries in one pass', function () {
    $path = makeTarball([
        './package/index.js' => 'module.exports = 1;',
        '/abs/path.js' => 'x',
        'package/link' => ['type' => '2', 'link' => '../../etc/passwd'],
        'package/bin' => "\x7fELF".str_repeat("\0", 60),
        'package/manifest.json' => '{"a":1}',
    ]);

    $inventory = (new ArchiveInspector(new SecretRules))->inspect($path, 'engine');

    expect($inventory->error)->toBeNull()
        ->and($inventory->gzipped)->toBeTrue()
        ->and(array_column($inventory->entries, 'name'))->toBe(['package/index.js', 'abs/path.js', 'package/link', 'package/bin', 'package/manifest.json'])
        ->and(array_column($inventory->entries, 'raw'))->toBe(['./package/index.js', '/abs/path.js', 'package/link', 'package/bin', 'package/manifest.json'])
        ->and($inventory->entries[2]['type'])->toBe('2')
        ->and($inventory->entries[2]['link'])->toBe('../../etc/passwd')
        ->and($inventory->entries[0]['sha256'])->toBe(hash('sha256', 'module.exports = 1;'))
        ->and($inventory->entries[3]['executable'])->toBe('ELF executable')
        ->and($inventory->kept)->toBe(['package/manifest.json' => '{"a":1}']);
});

it('stops at the entry and size limits', function () {
    config(['registry.releases.max_entries' => 2]);
    $inventory = (new ArchiveInspector)->inspect(makeTarball(['a' => '1', 'b' => '2', 'c' => '3']), 'api');
    expect($inventory->truncated)->toBeTrue()->and($inventory->entryCount())->toBe(2);

    config(['registry.releases.max_entries' => 100, 'registry.releases.max_unpacked_bytes' => 5]);
    $inventory = (new ArchiveInspector)->inspect(makeTarball(['a' => '1234', 'b' => '5678']), 'api');
    expect($inventory->truncated)->toBeTrue()->and($inventory->entryCount())->toBe(1);
});

it('reports a file that is not a tar archive', function () {
    $path = tempnam(sys_get_temp_dir(), 'x');
    file_put_contents($path, str_repeat('hello world ', 100));

    expect((new ArchiveInspector)->inspect($path, 'api')->error)->toBe('Not a tar archive (bad header checksum).');
});

it('tells safe paths from traversal', function (string $path, bool $safe) {
    expect(ArchiveInspector::isSafePath($path))->toBe($safe);
})->with([
    ['package/index.js', true],
    ['./package/', true],
    ['package/../index.js', false],
    ['../x', false],
    ['/etc/passwd', false],
    ['C:/Windows/x', false],
    ['..\\x', false],
]);

it('mirrors only safe regular files for gitleaks', function () {
    $dir = sys_get_temp_dir().'/mirror-'.uniqid();
    (new ArchiveInspector)->inspect(makeTarball(['package/a.js' => 'a', '../escape.js' => 'b']), 'engine', $dir.'/engine');

    expect(is_file($dir.'/engine/package/a.js'))->toBeTrue()
        ->and(is_file($dir.'/escape.js'))->toBeFalse()
        ->and(is_file(dirname($dir).'/escape.js'))->toBeFalse();
});
