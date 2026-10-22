<?php

use App\Jobs\RunReleaseChecks;
use App\Models\File;
use App\Models\Release;
use App\Models\ReleaseCheck;
use App\Models\Review;
use App\Models\Token;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

it('stores an upload, runs every check and keeps the release ready to submit', function () {
    [$account, , $extension] = publisher();

    $response = uploadRelease($account, $extension, '1.0.0', [], ['notes' => 'First cut'])->assertCreated();

    $response->assertJsonPath('version', '1.0.0')
        ->assertJsonPath('channel', 'stable')
        ->assertJsonPath('status', Release::STATUS_UPLOADED)
        ->assertJsonPath('checks_passed', true)
        ->assertJsonPath('compat', ['console' => '>=0.7.0 <0.8.0', 'api' => '^1.6'])
        ->assertJsonPath('notes', 'First cut')
        ->assertJsonPath('submission.requested', false);

    expect(collect($response->json('checks'))->pluck('check')->all())->toBe([
        'manifest', 'compat', 'package_names', 'version', 'size', 'archive', 'secrets_scan', 'dist',
    ]);
    expect(collect($response->json('checks'))->every(fn ($check) => $check['passed']))->toBeTrue();
    expect(collect($response->json('checks'))->firstWhere('check', 'dist')['skipped'])->toBeTrue();

    expect(collect($response->json('reviews'))->map(fn ($r) => [$r['action'], $r['from_status'], $r['to_status'], $r['actor']])->all())->toBe([
        ['upload', null, 'uploaded', 'publisher'],
        ['check', 'uploaded', 'checking', 'system'],
        ['pass_checks', 'checking', 'uploaded', 'system'],
    ]);

    $release = Release::findById($response->json('id'));
    expect($release->engineTarball->type)->toBe(File::TYPE_ENGINE_TARBALL)
        ->and($release->engineTarball->manifest()['name'])->toBe(packageName($extension, 'npm'))
        ->and($release->apiTarball->manifest()['name'])->toBe(packageName($extension, 'composer'))
        ->and($release->size_bytes)->toBe($release->engineTarball->size + $release->apiTarball->size)
        ->and($release->metaValue('package_names'))->toBe(['npm' => packageName($extension, 'npm'), 'composer' => packageName($extension, 'composer')]);
    Storage::disk('packages')->assertExists($release->engineTarball->path);
    expect($response->json('files.engine.sha256'))->toBe($release->engineTarball->checksum);
});

it('needs the publish scope and a publishing role in the listing organization', function () {
    [$account, , $extension] = publisher();

    actingAsAccount($account, [Token::SCOPE_READ]);
    $this->post('/v1/publisher/extensions/'.$extension->slug.'/releases', releaseFiles($extension, '1.0.0'), ['Accept' => 'application/json'])
        ->assertStatus(403);

    [$member, , $memberListing] = publisher('member');
    uploadRelease($member, $memberListing, '1.0.0')->assertNotFound();

    [$stranger] = publisher();
    uploadRelease($stranger, $extension, '1.0.0')->assertNotFound();

    [$publisherRole, , $publisherListing] = publisher('publisher');
    uploadRelease($publisherRole, $publisherListing, '1.0.0')->assertCreated();
});

it('refuses manifests it cannot create a release from', function () {
    [$account, , $extension] = publisher();

    $invalid = fn (string $manifest) => array_merge(releaseFiles($extension, '1.0.0'), ['manifest' => fakeUpload($manifest, 'extension.json')]);

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->post('/v1/publisher/extensions/'.$extension->slug.'/releases', $invalid('{not json'), ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonPath('errors.0', 'The manifest must be a JSON object (extension.json).');
    $this->post('/v1/publisher/extensions/'.$extension->slug.'/releases', $invalid('{"name": "x", "version": "1.0"}'), ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors('manifest', 'fields');
    $this->post('/v1/publisher/extensions/'.$extension->slug.'/releases', $invalid('{"name": "x", "version": "v1.0.0"}'), ['Accept' => 'application/json'])
        ->assertStatus(422);

    $noArchives = ['manifest' => releaseFiles($extension, '1.0.0')['manifest']];
    $this->post('/v1/publisher/extensions/'.$extension->slug.'/releases', $noArchives, ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonValidationErrors(['engine', 'api'], 'fields');

    uploadRelease($account, $extension, '1.0.0', ['manifest' => ['channel' => 'beta']], ['channel' => 'stable'])
        ->assertStatus(422)->assertJsonValidationErrors('channel', 'fields');

    expect(Release::count())->toBe(0);
});

it('takes the channel from the request or the manifest', function () {
    [$account, , $extension] = publisher();

    uploadRelease($account, $extension, '1.0.0-beta.1', ['manifest' => ['channel' => 'beta']])->assertCreated()->assertJsonPath('channel', 'beta');
    uploadRelease($account, $extension, '1.0.0-beta.2', [], ['channel' => 'beta'])->assertCreated()->assertJsonPath('channel', 'beta');
});

it('replaces a failed, rejected or unsubmitted upload of the same version but never a submitted or published one', function () {
    [$account, , $extension] = publisher();

    $failed = uploadRelease($account, $extension, '1.0.0', ['engineJson' => ['name' => '@other/thing']])
        ->assertCreated()->assertJsonPath('status', Release::STATUS_FAILED_CHECKS)->json('id');
    $failedRelease = Release::findById($failed);
    $failedPath = $failedRelease->engineTarball->path;

    $replacement = uploadRelease($account, $extension, '1.0.0')->assertCreated()->assertJsonPath('status', Release::STATUS_UPLOADED);
    expect($replacement->json('id'))->not->toBe($failed)
        ->and(Release::withTrashed()->where('version', '1.0.0')->count())->toBe(1)
        ->and(Review::where('release_uuid', $failedRelease->uuid)->count())->toBe(0)
        ->and(ReleaseCheck::where('release_uuid', $failedRelease->uuid)->count())->toBe(0);
    Storage::disk('packages')->assertMissing($failedPath);
    $this->assertDatabaseHas('events', ['kind' => 'release.replaced', 'subject_uuid' => $failedRelease->uuid]);

    // Unsubmitted and passing: replaceable too.
    uploadRelease($account, $extension, '1.0.0')->assertCreated();

    // Submitted: not replaceable.
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $id = Release::where('version', '1.0.0')->value('public_id');
    $this->postJson('/v1/publisher/releases/'.$id.'/submit')->assertOk()->assertJsonPath('status', Release::STATUS_SUBMITTED);

    uploadRelease($account, $extension, '1.0.0')->assertStatus(409)
        ->assertJsonPath('errors.0', 'Version 1.0.0 is already submitted; release a new version.');
});

it('refuses uploads to a suspended listing', function () {
    [$account, , $extension] = publisher(extension: ['status' => 'suspended']);

    uploadRelease($account, $extension, '1.0.0')->assertForbidden()->assertJsonPath('errors.0', 'This extension is suspended.');
});

it('marks the release failed when the checks job itself fails', function () {
    [$account, , $extension] = publisher();
    Queue::fake();

    $id = uploadRelease($account, $extension, '1.0.0')->assertCreated()->assertJsonPath('status', Release::STATUS_UPLOADED)->json('id');
    $release = Release::findById($id);
    expect($release->checks()->count())->toBe(0);
    Queue::assertPushed(RunReleaseChecks::class);

    (new RunReleaseChecks($release))->failed(new RuntimeException('disk gone'));

    expect($release->fresh()->status)->toBe(Release::STATUS_FAILED_CHECKS)
        ->and($release->reviews()->get()->last()->reason)->toContain('The checks could not run');
});
