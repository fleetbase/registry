<?php

use App\Support\Tarball;

it('reads package.json from an npm-packed tarball', function () {
    $manifest = Tarball::manifest(fixturePackage('hello-engine-1.0.0.tgz'), 'package.json');

    expect($manifest['name'])->toBe('@registry-demo/hello-engine')
        ->and($manifest['version'])->toBe('1.0.0');
});

it('reads composer.json from a single top-level folder', function () {
    $manifest = Tarball::manifest(fixturePackage('hello-api-1.0.0.tar.gz'), 'composer.json');

    expect($manifest['name'])->toBe('registry-demo/hello-api');
});

it('prefers the shallowest manifest and ignores nested ones', function () {
    $path = makeTarball([
        'package/node_modules/dep/package.json' => '{"name":"dep"}',
        'package/package.json' => '{"name":"root"}',
    ]);

    expect(Tarball::manifest($path, 'package.json'))->toBe(['name' => 'root']);

    $nestedOnly = makeTarball(['a/b/package.json' => '{"name":"deep"}']);
    expect(Tarball::manifest($nestedOnly, 'package.json'))->toBeNull();
});

it('handles uncompressed archives and GNU long names', function () {
    $long = 'package/'.str_repeat('nested-directory/', 8).'file.txt';
    $path = makeTarball(['composer.json' => '{"name":"a/b"}', $long => 'x'], gzip: false);

    expect(Tarball::manifest($path, 'composer.json'))->toBe(['name' => 'a/b'])
        ->and(Tarball::names($path))->toBe(['composer.json', $long]);
});

it('returns null for invalid json', function () {
    expect(Tarball::manifest(makeTarball(['package/package.json' => '{nope']), 'package.json'))->toBeNull();
});
