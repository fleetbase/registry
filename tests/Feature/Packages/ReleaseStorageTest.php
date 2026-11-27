<?php

use App\Models\File;
use App\Models\Release;
use App\Services\ReleaseStorage;
use Illuminate\Support\Facades\Storage;

it('stores a tarball under its prefix with all three digests and the manifest', function () {
    $release = Release::factory()->create();
    $source = fixturePackage('hello-engine-1.0.0.tgz');

    $file = app(ReleaseStorage::class)->store($source, File::TYPE_ENGINE_TARBALL, $release);

    expect($file->path)->toBe('tarballs/'.$file->uuid.'/hello-engine-1.0.0.tgz')
        ->and($file->disk)->toBe('packages')
        ->and($file->size)->toBe(filesize($source))
        ->and($file->checksum)->toBe(hash_file('sha256', $source))
        ->and($file->shasum)->toBe(sha1_file($source))
        ->and($file->integrity)->toBe('sha512-'.base64_encode(hash_file('sha512', $source, true)))
        ->and($file->mime)->toBe('application/gzip')
        ->and($file->subject_uuid)->toBe($release->uuid)
        ->and($file->manifest()['name'])->toBe('@registry-demo/hello-engine')
        ->and($file->public_id)->toStartWith('file_');

    Storage::disk('packages')->assertExists($file->path);
    expect(app(ReleaseStorage::class)->verify($file))->toBeTrue();
});

it('uses a prefix per file type', function (string $type, string $prefix) {
    $file = app(ReleaseStorage::class)->storeContents('bytes', $type, 'x.bin');

    expect($file->path)->toStartWith($prefix.'/');
})->with([
    [File::TYPE_ENGINE_TARBALL, 'tarballs'],
    [File::TYPE_API_TARBALL, 'tarballs'],
    [File::TYPE_ENGINE_DIST, 'dist'],
    [File::TYPE_ICON, 'icons'],
    [File::TYPE_SCREENSHOT, 'screenshots'],
]);

it('keeps the composer.json of an API tarball', function () {
    $file = app(ReleaseStorage::class)->store(fixturePackage('hello-api-1.0.0.tar.gz'), File::TYPE_API_TARBALL);

    expect($file->manifest()['name'])->toBe('registry-demo/hello-api')
        ->and($file->meta)->toHaveKey('composer_json');
});

it('sanitizes filenames and rejects unknown types', function () {
    $file = app(ReleaseStorage::class)->storeContents('x', File::TYPE_ICON, '../../etc/pass wd.png');
    expect($file->name)->toBe('pass-wd.png');

    app(ReleaseStorage::class)->storeContents('x', 'nonsense', 'x');
})->throws(InvalidArgumentException::class);

it('detects a corrupted object', function () {
    $file = app(ReleaseStorage::class)->storeContents('original', File::TYPE_ENGINE_DIST, 'dist.tgz');
    Storage::disk('packages')->put($file->path, 'tampered');

    expect(app(ReleaseStorage::class)->verify($file))->toBeFalse();
});

it('deletes the object with the row', function () {
    $storage = app(ReleaseStorage::class);
    $file = $storage->storeContents('x', File::TYPE_SCREENSHOT, 's.png');

    $storage->delete($file);

    Storage::disk('packages')->assertMissing($file->path);
    expect(File::find($file->uuid))->toBeNull()
        ->and(File::withTrashed()->find($file->uuid))->not->toBeNull();
});
