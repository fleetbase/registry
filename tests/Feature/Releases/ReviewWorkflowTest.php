<?php

use App\Jobs\RunReleaseChecks;
use App\Mail\ReleaseDecisionMail;
use App\Models\Extension;
use App\Models\Price;
use App\Models\Release;
use App\Models\Review;
use App\Models\Token;
use App\Releases\ReleasePipeline;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function timeline(Release|string $release): array
{
    $release = $release instanceof Release ? $release : Release::findById($release);

    return $release->reviews()->get()->map(fn (Review $r) => "{$r->action}:{$r->from_status}>{$r->to_status}:{$r->actor}")->all();
}

function submitAs($account, string $releaseId)
{
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);

    return test()->postJson('/v1/publisher/releases/'.$releaseId.'/submit');
}

function asStaff(array $scopes = [Token::SCOPE_REVIEW])
{
    $staff = staffAccount();
    actingAsAccount($staff, $scopes);

    return $staff;
}

it('sends a first release to human review and publishes it on approval', function () {
    Mail::fake();
    [$account, , $extension] = publisher();
    $npm = packageName($extension, 'npm');
    $composer = packageName($extension, 'composer');

    $id = uploadRelease($account, $extension, '1.0.0')->assertCreated()->json('id');

    // Nothing is visible before publication.
    $this->getJson('/v1/catalog/extensions')->assertOk()->assertJsonPath('meta.total', 0);
    $this->getJson('/npm/'.$npm)->assertNotFound();

    submitAs($account, $id)->assertOk()
        ->assertJsonPath('status', Release::STATUS_SUBMITTED)
        ->assertJsonPath('review.required', true)
        ->assertJsonPath('review.reasons.first_release', 'This is the extension\'s first release.');

    // The staff queue shows it; staff take it into review.
    $staff = asStaff();
    $this->getJson('/v1/admin/releases')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $id);
    $this->postJson('/v1/admin/releases/'.$id.'/claim')->assertOk()->assertJsonPath('status', Release::STATUS_IN_REVIEW);

    $this->postJson('/v1/admin/releases/'.$id.'/approve', ['items' => [['code' => 'R1', 'passed' => true]]])->assertOk()
        ->assertJsonPath('status', Release::STATUS_PUBLISHED)
        ->assertJsonPath('extension.status', Extension::STATUS_PUBLISHED);

    $release = Release::findById($id);
    expect($release->published_at)->not->toBeNull()
        ->and($release->metaValue('price.model'))->toBe(Price::MODEL_FREE)
        ->and($extension->fresh()->published_at)->not->toBeNull();

    expect(timeline($release))->toBe([
        'upload:>uploaded:publisher',
        'check:uploaded>checking:system',
        'pass_checks:checking>uploaded:system',
        'submit:uploaded>submitted:publisher',
        'claim:submitted>in_review:staff',
        'approve:in_review>approved:staff',
        'publish:approved>published:staff',
    ]);
    expect($release->reviews()->where('action', 'approve')->first()->reviewer_account_uuid)->toBe($staff->uuid);

    // Visible in the catalog (cache flushed), the npm packument and the Composer repository.
    $this->getJson('/v1/catalog/extensions')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.slug', $extension->slug);
    $this->getJson('/v1/catalog/extensions/'.$extension->slug.'/releases')->assertOk()->assertJsonPath('latest', '1.0.0');
    $this->getJson('/npm/'.$npm)->assertOk()->assertJsonPath('dist-tags.latest', '1.0.0');
    $this->getJson('/composer/p2/'.$composer.'.json')->assertOk()->assertJsonPath('packages.'.$composer.'.0.version', '1.0.0');

    Mail::assertQueued(ReleaseDecisionMail::class, fn ($mail) => $mail->decision === ReleaseDecisionMail::PUBLISHED && $mail->hasTo($account->email));
});

it('rejects with a required reason and items the publisher can read, then takes a resubmission', function () {
    Mail::fake();
    [$account, , $extension] = publisher();
    $id = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->assertCreated()
        ->assertJsonPath('status', Release::STATUS_SUBMITTED)->json('id');

    asStaff();
    $this->postJson('/v1/admin/releases/'.$id.'/reject', ['reason' => 'Needs work'])
        ->assertStatus(422)->assertJsonValidationErrors('items', 'fields');
    $this->postJson('/v1/admin/releases/'.$id.'/reject', ['items' => [['code' => 'R2', 'passed' => false]]])
        ->assertStatus(422)->assertJsonValidationErrors('reason', 'fields');
    $this->postJson('/v1/admin/releases/'.$id.'/reject', ['reason' => 'Needs work', 'items' => [['code' => 'R2']]])
        ->assertStatus(422)->assertJsonValidationErrors('items.0.passed', 'fields');

    $this->postJson('/v1/admin/releases/'.$id.'/reject', [
        'reason' => 'The listing screenshots do not match the extension.',
        'items' => [['code' => 'R1', 'passed' => true], ['code' => 'R4', 'passed' => false, 'note' => 'Screenshots show another product.']],
    ])->assertOk()->assertJsonPath('status', Release::STATUS_REJECTED);

    Mail::assertQueued(ReleaseDecisionMail::class, fn ($mail) => $mail->decision === ReleaseDecisionMail::REJECTED);

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $rejection = collect($this->getJson('/v1/publisher/releases/'.$id)->assertOk()->json('reviews'))->firstWhere('action', 'reject');
    expect($rejection['reason'])->toBe('The listing screenshots do not match the extension.')
        ->and($rejection['items'][1])->toBe(['code' => 'R4', 'passed' => false, 'note' => 'Screenshots show another product.'])
        ->and($rejection['actor'])->toBe('staff')
        ->and($rejection['by']['name'])->toBe('Staff Reviewer');

    // Resubmitting the same release goes back to the queue.
    submitAs($account, $id)->assertOk()->assertJsonPath('status', Release::STATUS_SUBMITTED);
});

it('can approve without publishing and publish later', function () {
    [$account, , $extension] = publisher();
    $id = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->json('id');

    asStaff();
    $this->postJson('/v1/admin/releases/'.$id.'/publish')->assertStatus(409);
    $this->postJson('/v1/admin/releases/'.$id.'/approve', ['publish' => false, 'reason' => 'Looks good'])->assertOk()
        ->assertJsonPath('status', Release::STATUS_APPROVED);
    $this->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 0);

    $this->postJson('/v1/admin/releases/'.$id.'/publish')->assertOk()->assertJsonPath('status', Release::STATUS_PUBLISHED);
    $this->postJson('/v1/admin/releases/'.$id.'/publish')->assertStatus(409)->assertJsonPath('errors.0', 'A release that is published cannot be published.');
    $this->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 1);
});

it('auto-approves and publishes an update when nothing D6 covers changed', function () {
    Mail::fake();
    [$account, , $extension] = publisher();
    publishedRelease($account, $extension, '1.0.0');
    $this->getJson('/npm/'.packageName($extension, 'npm'))->assertJsonPath('dist-tags.latest', '1.0.0');

    $id = uploadRelease($account, $extension, '1.1.0', [], ['submit' => '1'])->assertCreated()
        ->assertJsonPath('status', Release::STATUS_PUBLISHED)
        ->assertJsonPath('review.required', false)
        ->json('id');

    expect(array_slice(timeline($id), 3))->toBe([
        'submit:uploaded>submitted:publisher',
        'approve:submitted>approved:system',
        'publish:approved>published:system',
    ]);
    expect(Release::findById($id)->reviews()->where('action', 'approve')->first()->reason)->toContain('review policy D6');

    // dist-tags follow immediately.
    $this->getJson('/npm/'.packageName($extension, 'npm'))->assertJsonPath('dist-tags.latest', '1.1.0');
});

it('sends updates that change permissions, pricing or package names to human review', function () {
    [$account, $organization, $extension] = publisher();
    publishedRelease($account, $extension, '1.0.0', ['manifest' => ['permissions' => ['read orders']]]);

    // Same permissions in another order: no review.
    uploadRelease($account, $extension, '1.0.1', ['manifest' => ['permissions' => ['read orders']]], ['submit' => '1'])
        ->assertJsonPath('status', Release::STATUS_PUBLISHED);

    uploadRelease($account, $extension, '1.1.0', ['manifest' => ['permissions' => ['read orders', 'write orders']]], ['submit' => '1'])
        ->assertJsonPath('status', Release::STATUS_SUBMITTED)
        ->assertJsonPath('review.reasons.permissions', 'The manifest\'s permissions changed.');

    $extension->price->forceFill(['model' => Price::MODEL_ONE_TIME, 'amount' => 4900, 'currency' => 'USD'])->save();
    uploadRelease($account, $extension, '1.2.0', ['manifest' => ['permissions' => ['read orders']]], ['submit' => '1'])
        ->assertJsonPath('status', Release::STATUS_SUBMITTED)
        ->assertJsonPath('review.reasons.pricing', 'Pricing changed from free to 49.00 USD (one_time).');

    $extension->price->forceFill(['model' => Price::MODEL_FREE, 'amount' => 0])->save();
    $response = uploadRelease($account, $extension, '1.3.0', ['api' => false, 'manifest' => ['permissions' => ['read orders']]], ['submit' => '1'])
        ->assertJsonPath('status', Release::STATUS_SUBMITTED);
    expect($response->json('review.reasons.package_names'))->toStartWith('Package names changed from');
});

it('remembers a submission made while checks are running', function () {
    [$account, , $extension] = publisher();
    Queue::fake();

    $id = uploadRelease($account, $extension, '1.0.0')->assertCreated()->json('id');
    submitAs($account, $id)->assertStatus(202)
        ->assertJsonPath('status', Release::STATUS_UPLOADED)
        ->assertJsonPath('submission.requested', true);

    (new RunReleaseChecks(Release::findById($id)))->handle(app(ReleasePipeline::class));

    expect(Release::findById($id)->status)->toBe(Release::STATUS_SUBMITTED);

    // Running the job again does nothing.
    (new RunReleaseChecks(Release::findById($id)))->handle(app(ReleasePipeline::class));
    expect(timeline($id))->toHaveCount(4);
});

it('refuses to submit a release with failing checks or an unready listing', function () {
    [$account, , $extension] = publisher(extension: ['privacy_url' => null, 'copyright' => '']);

    $failing = uploadRelease($account, $extension, '1.0.0', ['engineJson' => ['name' => '@x/y']])->json('id');
    submitAs($account, $failing)->assertStatus(409);

    $id = uploadRelease($account, $extension, '1.0.1')->json('id');
    $response = submitAs($account, $id)->assertStatus(422);
    expect($response->json('errors'))->toBe(['The listing needs a copyright notice.', 'The listing needs a valid privacy policy URL.']);

    // When the submission was requested at upload, the reasons are kept on the release.
    uploadRelease($account, $extension, '1.0.2', [], ['submit' => '1'])->assertCreated()
        ->assertJsonPath('status', Release::STATUS_UPLOADED)
        ->assertJsonPath('submission.requested', false)
        ->assertJsonPath('submission.errors.0', 'The listing needs a copyright notice.');
});

it('withdraws a release from review', function () {
    [$account, , $extension] = publisher();
    $id = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->json('id');

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->postJson('/v1/publisher/releases/'.$id.'/withdraw', ['reason' => 'Found a bug'])->assertOk()
        ->assertJsonPath('status', Release::STATUS_UPLOADED);
    $this->postJson('/v1/publisher/releases/'.$id.'/withdraw')->assertStatus(409)
        ->assertJsonPath('errors.0', 'A release that is uploaded cannot be withdrawn.');

    expect(timeline($id)[4])->toBe('withdraw:submitted>uploaded:publisher');
    asStaff();
    $this->getJson('/v1/admin/releases')->assertJsonPath('meta.total', 0);
});

it('yanks a published release from metadata but keeps its tarball downloadable', function () {
    [$account, , $extension] = publisher();
    $npm = packageName($extension, 'npm');
    publishedRelease($account, $extension, '1.0.0');
    $release = publishedRelease($account, $extension, '1.1.0');

    $this->getJson('/v1/catalog/extensions/'.$extension->slug.'/releases')->assertJsonPath('latest', '1.1.0');

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->postJson('/v1/publisher/releases/'.$release->public_id.'/yank', ['reason' => 'Breaks installs'])->assertOk()
        ->assertJsonPath('status', Release::STATUS_YANKED);
    expect($release->fresh()->yanked_at)->not->toBeNull();

    $this->getJson('/v1/catalog/extensions/'.$extension->slug.'/releases')->assertJsonPath('latest', '1.0.0');
    $this->getJson('/npm/'.$npm)->assertJsonPath('dist-tags.latest', '1.0.0')->assertJsonMissingPath('versions.1\\.1\\.0');
    $this->get('/npm/'.$npm.'/-/'.basename($npm).'-1.1.0.tgz')->assertOk();

    $this->postJson('/v1/publisher/releases/'.$release->public_id.'/yank')->assertStatus(409);

    // A yanked version still counts for "greater than the last release".
    $retry = uploadRelease($account, $extension, '1.1.0')->assertStatus(409);
});

it('suspends a listing out of the catalog and the package endpoints', function () {
    [$account, , $extension] = publisher();
    publishedRelease($account, $extension, '1.0.0');
    $pending = uploadRelease($account, $extension, '1.1.0', ['manifest' => ['permissions' => ['x']]], ['submit' => '1'])
        ->assertJsonPath('status', Release::STATUS_SUBMITTED)->json('id');
    $this->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 1);

    asStaff();
    $this->postJson('/v1/admin/extensions/'.$extension->slug.'/suspend')->assertStatus(422);
    $this->postJson('/v1/admin/extensions/'.$extension->slug.'/suspend', ['reason' => 'Malware report'])->assertOk()
        ->assertJsonPath('status', Extension::STATUS_SUSPENDED)
        ->assertJsonPath('suspension.reason', 'Malware report');

    $this->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 0);
    $this->getJson('/npm/'.packageName($extension, 'npm'))->assertNotFound();

    $this->postJson('/v1/admin/releases/'.$pending.'/approve')->assertStatus(422)->assertJsonPath('errors.0', 'The listing is suspended.');
    uploadRelease($account, $extension, '1.2.0')->assertForbidden();
});

it('keeps the admin API to staff with the review scope', function () {
    [$account, , $extension] = publisher();
    $id = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->json('id');

    actingAsAccount($account, [Token::SCOPE_ALL]);
    $this->getJson('/v1/admin/releases')->assertForbidden()->assertJsonPath('errors.0', 'Only Fleetbase staff can do this.');
    $this->postJson('/v1/admin/releases/'.$id.'/approve')->assertForbidden();

    asStaff([Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/admin/releases')->assertForbidden()->assertJsonPath('errors.0', "This token does not have the 'review' scope.");

    asStaff([Token::SCOPE_ALL]);
    $this->getJson('/v1/admin/releases?status=submitted')->assertOk()->assertJsonPath('meta.total', 1);
    $this->getJson('/v1/admin/releases?status=bogus')->assertStatus(422);
    $this->getJson('/v1/admin/releases/'.$id)->assertOk()->assertJsonPath('extension.slug', $extension->slug);
    $this->postJson('/v1/admin/releases/rel_missing/approve')->assertNotFound();
});

it('enforces the state machine', function () {
    [$account, , $extension] = publisher();
    $id = uploadRelease($account, $extension, '1.0.0')->json('id');

    asStaff();
    $this->postJson('/v1/admin/releases/'.$id.'/claim')->assertStatus(409)->assertJsonPath('errors.0', 'A release that is uploaded cannot be taken into review.');
    $this->postJson('/v1/admin/releases/'.$id.'/approve')->assertStatus(409);
    $this->postJson('/v1/admin/releases/'.$id.'/reject', ['reason' => 'nope', 'items' => [['code' => 'R1', 'passed' => false]]])->assertStatus(409);

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->postJson('/v1/publisher/releases/'.$id.'/yank')->assertStatus(409)->assertJsonPath('errors.0', 'A release that is uploaded cannot be yanked.');

    // Other publishers cannot see or act on it.
    [$stranger] = publisher();
    actingAsAccount($stranger, [Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/publisher/releases/'.$id)->assertNotFound();
    $this->postJson('/v1/publisher/releases/'.$id.'/submit')->assertNotFound();
});

it('lists an extension\'s releases for its publisher', function () {
    [$account, , $extension] = publisher();
    uploadRelease($account, $extension, '1.0.0');
    uploadRelease($account, $extension, '1.1.0-beta.1', [], ['channel' => 'beta']);

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/publisher/extensions/'.$extension->slug.'/releases')->assertOk()
        ->assertJsonCount(2)
        ->assertJsonPath('0.version', '1.1.0-beta.1')
        ->assertJsonPath('1.checks_passed', true);
    $this->getJson('/v1/publisher/extensions/'.$extension->slug)->assertJsonCount(2, 'releases');
});
