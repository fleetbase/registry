<?php

use App\Models\Release;
use App\Models\Token;
use App\Releases\ReleasePipeline;
use App\Support\CatalogCache;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

it('lets the publisher edit a release’s notes without review', function () {
    [$account, , $extension] = publisher();
    $id = uploadRelease($account, $extension, '0.1.0', [], ['notes' => 'First cut.'])->assertCreated()->json('id');
    $timeline = Release::findById($id)->reviews()->count();

    $this->patchJson("/v1/publisher/releases/{$id}", ['notes' => '  <p>Faster packing slips.</p>  '])
        ->assertOk()
        ->assertJsonPath('id', $id)
        ->assertJsonPath('notes', '<p>Faster packing slips.</p>')
        ->assertJsonPath('status', Release::STATUS_UPLOADED);

    $release = Release::findById($id);
    expect($release->notes)->toBe('<p>Faster packing slips.</p>')
        // The timeline has no entry type for a notes edit; the event log records it.
        ->and($release->reviews()->count())->toBe($timeline);
    $this->assertDatabaseHas('events', ['kind' => 'release.notes_updated', 'subject_uuid' => $release->uuid]);

    // Empty clears them; the key is required and the length is the upload's.
    $this->patchJson("/v1/publisher/releases/{$id}", ['notes' => ''])->assertOk()->assertJsonPath('notes', null);
    $this->patchJson("/v1/publisher/releases/{$id}", [])->assertStatus(422)->assertJsonValidationErrors('notes', 'fields');
    $this->patchJson("/v1/publisher/releases/{$id}", ['notes' => str_repeat('a', 20001)])->assertStatus(422)->assertJsonValidationErrors('notes', 'fields');
});

it('answers 404 for another publisher’s release', function () {
    [$account, , $extension] = publisher();
    $id = uploadRelease($account, $extension, '0.1.0')->assertCreated()->json('id');

    [$other] = publisher();
    actingAsAccount($other, [Token::SCOPE_PUBLISH]);
    $this->patchJson("/v1/publisher/releases/{$id}", ['notes' => 'Mine now'])->assertNotFound();

    actingAsAccount($account, [Token::SCOPE_READ]);
    $this->patchJson("/v1/publisher/releases/{$id}", ['notes' => 'Read only'])->assertForbidden();
    expect(Release::findById($id)->notes)->not->toBe('Mine now');
});

it('refuses to edit a yanked release’s notes', function () {
    [$account, , $extension] = publisher();
    $release = publishedRelease($account, $extension, '1.0.0');
    app(ReleasePipeline::class)->yank($release, $account, 'Broken');

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->patchJson("/v1/publisher/releases/{$release->public_id}", ['notes' => 'Too late'])
        ->assertStatus(422)->assertJsonPath('errors.0', 'A yanked release\'s notes cannot be changed.');
});

it('shows a published release’s new notes in the catalog at once', function () {
    [$account, , $extension] = publisher();
    $release = publishedRelease($account, $extension, '1.0.0');
    expect($release->status)->toBe(Release::STATUS_PUBLISHED);

    // Prime the catalog cache.
    $this->getJson("/v1/catalog/extensions/{$extension->slug}/releases")->assertOk()->assertJsonPath('releases.0.version', '1.0.0');
    $generation = CatalogCache::generation();

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->patchJson("/v1/publisher/releases/{$release->public_id}", ['notes' => 'Now with templates.'])->assertOk();

    expect(CatalogCache::generation())->not->toBe($generation);
    $this->getJson("/v1/catalog/extensions/{$extension->slug}/releases")->assertOk()->assertJsonPath('releases.0.notes', 'Now with templates.');
});

it('gives the publisher’s release summaries their submitted and decided dates', function () {
    [$account, , $extension] = publisher();
    $this->travelTo(now()->subDays(3));
    $id = uploadRelease($account, $extension, '0.1.0', [], ['submit' => '1'])->assertCreated()->json('id');
    $this->travelBack();

    $release = Release::findById($id);
    expect($release->status)->toBe(Release::STATUS_SUBMITTED);

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $submitted = $this->getJson('/v1/publisher/extensions')->assertOk()
        ->assertJsonPath('0.releases.0.id', $id)
        ->assertJsonPath('0.releases.0.decided_at', null)
        ->json('0.releases.0.submitted_at');
    expect(now()->diffInDays($submitted, true))->toBeGreaterThanOrEqual(2.9)->toBeLessThan(3.1);
    $this->getJson("/v1/publisher/extensions/{$extension->slug}")->assertOk()->assertJsonPath('releases.0.submitted_at', $submitted);

    app(ReleasePipeline::class)->approve($release, staffAccount(), publish: false);
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $decided = $this->getJson('/v1/publisher/extensions')->assertOk()
        ->assertJsonPath('0.releases.0.status', Release::STATUS_APPROVED)
        ->assertJsonPath('0.releases.0.submitted_at', $submitted)
        ->json('0.releases.0.decided_at');
    expect($decided)->not->toBeNull()
        ->and(now()->diffInMinutes($decided, true))->toBeLessThan(1);

    // Withdrawn, it is no longer submitted.
    app(ReleasePipeline::class)->withdraw($release->fresh(), $account);
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/publisher/extensions')->assertOk()
        ->assertJsonPath('0.releases.0.status', Release::STATUS_UPLOADED)
        ->assertJsonPath('0.releases.0.submitted_at', null);
});
