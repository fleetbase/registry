<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\Instance;
use App\Models\Release;
use App\Models\ReleaseCheck;
use App\Models\Review;
use App\Models\Token;
use App\Releases\ReleasePipeline;

/*
 * Private releases: every automated check (secrets scan included), no human review, published
 * as soon as they are submitted with passing checks. Going public sends the next release to
 * review as a first release, and only that publication makes the listing public.
 */

function privateListing(array $attributes = []): array
{
    return publisher(extension: array_merge(['visibility' => Extension::VISIBILITY_PRIVATE], $attributes));
}

function setVisibility($account, Extension $extension, string $visibility)
{
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    return test()->patchJson('/v1/publisher/extensions/'.$extension->slug, ['visibility' => $visibility]);
}

it('publishes a private extension\'s first release without human review after every check passes', function () {
    [$account, , $extension] = privateListing();

    $id = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->assertCreated()->json('id');
    $release = Release::findById($id);

    expect($release->status)->toBe(Release::STATUS_PUBLISHED)
        ->and($release->metaValue('review_policy.required'))->toBeFalse()
        ->and($release->metaValue('review_policy.visibility'))->toBe('private')
        ->and($release->metaValue('visibility'))->toBe('private')
        ->and($release->checks()->pluck('check')->sort()->values()->all())->toBe(collect(ReleasePipeline::CHECKS)->map(fn ($c) => app($c)->name())->sort()->values()->all())
        ->and($release->checks()->where('passed', false)->count())->toBe(0)
        ->and($release->reviews()->where('action', Review::ACTION_APPROVE)->value('reason'))->toContain('private extension');
    expect($extension->fresh()->status)->toBe(Extension::STATUS_PUBLISHED);

    // published, yet invisible to the public
    $this->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 0);
    $this->getJson('/npm/'.packageName($extension, 'npm'))->assertNotFound();

    // and visible to the owner's instances
    $instance = Instance::factory()->create(['organization_uuid' => $extension->organization_uuid]);
    $this->getJson('/npm/'.packageName($extension, 'npm'), instanceHeaders($instance))->assertOk()->assertJsonPath('dist-tags.latest', '1.0.0');
});

it('still fails a private release on the secrets scan', function () {
    [$account, , $extension] = privateListing();

    $id = uploadRelease($account, $extension, '1.0.0', ['engine' => ['package/config.js' => "key = 'AKIA"."QYLPMN5HHHFPZAM2'"]], ['submit' => '1'])->json('id');

    expect(Release::findById($id)->status)->toBe(Release::STATUS_FAILED_CHECKS)
        ->and(checkOf($id, ReleaseCheck::CHECK_SECRETS_SCAN)['passed'])->toBeFalse();
});

it('needs only a name on a private listing before its first release', function () {
    [$account, , $extension] = privateListing();
    $extension->forceFill(['subtitle' => null, 'description' => null, 'promotional_text' => null, 'tags' => null, 'website_url' => null, 'support_url' => null, 'privacy_url' => null, 'category_uuid' => null, 'fa_icon' => null, 'icon_file_uuid' => null, 'copyright' => null])->save();

    $id = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->json('id');

    expect(Release::findById($id)->status)->toBe(Release::STATUS_PUBLISHED);
});

it('switches public to private at once and hides the listing', function () {
    [$account, , $extension] = publisher();
    publishedRelease($account, $extension, '1.0.0');
    $this->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 1);

    setVisibility($account, $extension, 'private')->assertOk()
        ->assertJsonPath('visibility', 'private')->assertJsonPath('pending_visibility', null);

    $this->withoutHeader('Authorization')->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 0);
    $this->getJson('/npm/'.packageName($extension, 'npm'))->assertNotFound();

    // the next release is private, so it skips review
    $id = uploadRelease($account, $extension, '1.1.0', [], ['submit' => '1'])->json('id');
    expect(Release::findById($id)->status)->toBe(Release::STATUS_PUBLISHED);
});

it('makes a never-released private listing public at once', function () {
    [$account, , $extension] = privateListing();

    setVisibility($account, $extension, 'public')->assertOk()->assertJsonPath('visibility', 'public')->assertJsonPath('pending_visibility', null);

    $id = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->json('id');
    expect(Release::findById($id)->status)->toBe(Release::STATUS_SUBMITTED);
});

it('sends a private listing going public through first-release review and makes it public on approval', function () {
    [$account, , $extension] = privateListing();
    uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1']);
    expect($extension->fresh()->status)->toBe(Extension::STATUS_PUBLISHED);

    setVisibility($account, $extension, 'public')->assertOk()
        ->assertJsonPath('visibility', 'private')
        ->assertJsonPath('pending_visibility', 'public')
        ->assertJsonPath('readiness.ready', true);

    // still private while the request waits
    $this->withoutHeader('Authorization')->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 0);

    $id = uploadRelease($account, $extension, '1.1.0', [], ['submit' => '1'])->json('id');
    $release = Release::findById($id);
    expect($release->status)->toBe(Release::STATUS_SUBMITTED)
        ->and($release->metaValue('review_policy.reasons.first_release'))->toContain('private to public');

    app(ReleasePipeline::class)->approve($release, staffAccount());

    expect($extension->fresh()->visibility)->toBe('public')
        ->and($extension->fresh()->pendingVisibility())->toBeNull()
        ->and($release->fresh()->metaValue('visibility'))->toBe('public');
    $this->withoutHeader('Authorization')->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 1);

    // the reviewed public release is now the baseline: an unchanged update auto-publishes
    $next = uploadRelease($account, $extension, '1.2.0', [], ['submit' => '1'])->json('id');
    expect(Release::findById($next)->status)->toBe(Release::STATUS_PUBLISHED);
});

it('treats releases published while private as no baseline once the listing was public before', function () {
    [$account, , $extension] = publisher();
    publishedRelease($account, $extension, '1.0.0');
    setVisibility($account, $extension, 'private')->assertOk();
    uploadRelease($account, $extension, '1.1.0', [], ['submit' => '1']);

    setVisibility($account, $extension, 'public')->assertOk()->assertJsonPath('pending_visibility', 'public');
    $id = uploadRelease($account, $extension, '1.2.0', [], ['submit' => '1'])->json('id');

    expect(Release::findById($id)->status)->toBe(Release::STATUS_SUBMITTED);
});

it('cancels a pending public switch', function () {
    [$account, , $extension] = privateListing();
    uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1']);

    setVisibility($account, $extension, 'public')->assertJsonPath('pending_visibility', 'public');
    setVisibility($account, $extension, 'private')->assertOk()->assertJsonPath('pending_visibility', null);

    $id = uploadRelease($account, $extension, '1.1.0', [], ['submit' => '1'])->json('id');
    expect(Release::findById($id)->status)->toBe(Release::STATUS_PUBLISHED);
});

it('lets only owners and admins change visibility', function () {
    [$account, , $extension] = privateListing();
    [$publisherAccount] = [Account::factory()->create()];
    $publisherAccount->organizations()->attach($extension->organization_uuid, ['role' => 'publisher']);

    setVisibility($publisherAccount, $extension, 'public')->assertForbidden();
    setVisibility($account, $extension, 'nope')->assertUnprocessable();
});

it('creates a listing as private', function () {
    [$account, $organization] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    $this->postJson('/v1/publisher/extensions', [
        'name' => 'Client Only',
        'organization' => $organization->slug,
        'visibility' => 'private',
        'packages' => ['npm' => '@'.$organization->slug.'/client-only-engine'],
    ])->assertCreated()->assertJsonPath('visibility', 'private')->assertJsonPath('readiness.ready', true);
});
