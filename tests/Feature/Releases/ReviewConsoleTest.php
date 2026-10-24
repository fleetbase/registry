<?php

use App\Mail\ReleaseDecisionMail;
use App\Models\Release;
use App\Models\Review;
use App\Models\Token;
use App\Releases\ReleasePipeline;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * An update that needs a human review because its permissions changed.
 */
function reviewedUpdate($account, $extension, string $version): string
{
    return uploadRelease($account, $extension, $version, ['manifest' => ['permissions' => ['write orders '.$version]]], ['submit' => '1'])
        ->assertCreated()
        ->assertJsonPath('status', Release::STATUS_SUBMITTED)
        ->json('id');
}

it('gives admin releases the live version they update', function () {
    [$account, , $extension] = publisher();
    $first = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->json('id');

    actingAsAccount(staffAccount(), [Token::SCOPE_REVIEW]);
    $this->getJson('/v1/admin/releases')->assertOk()
        ->assertJsonPath('data.0.id', $first)
        ->assertJsonPath('data.0.previous_version', null);
    $this->getJson('/v1/admin/releases/'.$first)->assertOk()
        ->assertJsonPath('previous_version', null)
        ->assertJsonPath('previous', null);

    // 1.0.0 goes live, 1.1.0 goes live and is yanked: 1.2.0 updates 1.0.0.
    app(ReleasePipeline::class)->approve(Release::findById($first), staffAccount());
    $yanked = publishedRelease($account, $extension, '1.1.0');
    app(ReleasePipeline::class)->yank($yanked, $account);
    $id = reviewedUpdate($account, $extension, '1.2.0');

    actingAsAccount(staffAccount(), [Token::SCOPE_REVIEW]);
    $this->getJson('/v1/admin/releases')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $id)
        ->assertJsonPath('data.0.previous_version', '1.0.0')
        ->assertJsonMissingPath('data.0.previous');
    $this->getJson('/v1/admin/releases/'.$id)->assertOk()
        ->assertJsonPath('previous_version', '1.0.0')
        ->assertJsonPath('previous.id', $first)
        ->assertJsonPath('previous.version', '1.0.0')
        ->assertJsonPath('previous.manifest.version', '1.0.0')
        ->assertJsonPath('manifest.permissions', ['write orders 1.2.0']);

    // A published release counts only what went out before it.
    $this->getJson('/v1/admin/releases?status=published')->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $first)
        ->assertJsonPath('data.0.previous_version', null);

    // The publisher's view of the release does not carry it.
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->getJson('/v1/publisher/releases/'.$id)->assertOk()
        ->assertJsonMissingPath('previous_version')
        ->assertJsonMissingPath('previous');
});

it('works out previous versions for the whole queue in one query', function () {
    $queueQueries = function () {
        actingAsAccount(staffAccount(), [Token::SCOPE_REVIEW]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/v1/admin/releases')->assertOk();
        DB::disableQueryLog();

        return [count(DB::getQueryLog()), $response];
    };

    $update = function () {
        [$account, , $extension] = publisher();
        publishedRelease($account, $extension, '0.2.1');

        return reviewedUpdate($account, $extension, '0.3.0');
    };

    $update();
    [$one] = $queueQueries();

    $update();
    $update();
    [$three, $response] = $queueQueries();

    $response->assertJsonPath('meta.total', 3)
        ->assertJsonPath('data.0.previous_version', '0.2.1')
        ->assertJsonPath('data.2.previous_version', '0.2.1');
    expect($three)->toBe($one);
});

it('requests changes on an approved release that is not published yet', function () {
    Mail::fake();
    [$account, , $extension] = publisher();
    $id = uploadRelease($account, $extension, '1.0.0', [], ['submit' => '1'])->json('id');

    $staff = staffAccount();
    actingAsAccount($staff, [Token::SCOPE_REVIEW]);
    $this->postJson('/v1/admin/releases/'.$id.'/approve', ['publish' => false])->assertOk()->assertJsonPath('status', Release::STATUS_APPROVED);

    // Same validation as from review: a reason and at least one item.
    $this->postJson('/v1/admin/releases/'.$id.'/reject', ['items' => [['code' => 'R3', 'passed' => false]]])
        ->assertStatus(422)->assertJsonValidationErrors('reason', 'fields');

    $this->postJson('/v1/admin/releases/'.$id.'/reject', [
        'reason' => 'The support link 404s.',
        'items' => [['code' => 'R1', 'passed' => true], ['code' => 'R3', 'passed' => false, 'note' => 'support_url is dead.']],
    ])->assertOk()
        ->assertJsonPath('status', Release::STATUS_REJECTED)
        ->assertJsonPath('previous_version', null);

    $review = Release::findById($id)->reviews()->where('action', Review::ACTION_REJECT)->sole();
    expect($review->from_status)->toBe(Release::STATUS_APPROVED)
        ->and($review->to_status)->toBe(Release::STATUS_REJECTED)
        ->and($review->actor)->toBe(Review::ACTOR_STAFF)
        ->and($review->reviewer_account_uuid)->toBe($staff->uuid)
        ->and($review->reason)->toBe('The support link 404s.')
        ->and($review->items[1])->toBe(['code' => 'R3', 'passed' => false, 'note' => 'support_url is dead.']);
    Mail::assertQueued(ReleaseDecisionMail::class, fn ($mail) => $mail->decision === ReleaseDecisionMail::REJECTED);

    // Nothing went live, and the publisher can fix and resubmit.
    $this->getJson('/v1/catalog/extensions')->assertJsonPath('meta.total', 0);
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $this->postJson('/v1/publisher/releases/'.$id.'/submit')->assertOk()->assertJsonPath('status', Release::STATUS_SUBMITTED);
});

it('refuses to request changes on a published release', function () {
    [$account, , $extension] = publisher();
    $release = publishedRelease($account, $extension, '1.0.0');

    actingAsAccount(staffAccount(), [Token::SCOPE_REVIEW]);
    $this->postJson('/v1/admin/releases/'.$release->public_id.'/reject', ['reason' => 'Too late', 'items' => [['code' => 'R1', 'passed' => false]]])
        ->assertStatus(409)
        ->assertJsonPath('errors.0', 'A release that is published cannot be rejected.');

    expect($release->fresh()->status)->toBe(Release::STATUS_PUBLISHED)
        ->and($release->reviews()->where('action', Review::ACTION_REJECT)->exists())->toBeFalse();
});
