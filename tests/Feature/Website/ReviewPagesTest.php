<?php

use App\Models\Extension;
use App\Models\Release;
use App\Releases\ReleasePipeline;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A publisher's first release, uploaded, checked and submitted: it waits for staff review.
 */
function submittedRelease(string $version = '1.0.0', array $extension = []): Release
{
    [$account, , $listing] = publisher(extension: $extension);
    $id = uploadRelease($account, $listing, $version, [], ['submit' => '1'])->assertCreated()->json('id');
    test()->flushHeaders();

    return Release::findById($id);
}

it('shows staff the queue tabs with counts, oldest first, and who claimed a release', function () {
    $older = submittedRelease(extension: ['name' => 'Older']);
    $older->forceFill(['updated_at' => now()->subDays(3)])->saveQuietly();
    $claimed = submittedRelease(extension: ['name' => 'Claimed']);
    $failed = makeRelease(makePackage('npm', '@acme/broken-engine'), '0.1.0', status: Release::STATUS_FAILED_CHECKS);
    $rejected = makeRelease(makePackage('npm', '@acme/nope-engine'), '0.2.0', status: Release::STATUS_REJECTED);

    $staff = staffAccount();
    app(ReleasePipeline::class)->claim($claimed, $staff);
    actingOnWebsite($staff);

    $this->get('/review')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Review/Queue')
            ->where('tab', 'waiting')
            ->where('counts', ['waiting' => 2, 'publish' => 0, 'suspended' => 0])
            ->has('releases.data', 2)
            ->where('releases.data.0.id', $older->public_id)
            ->where('releases.data.0.status', Release::STATUS_SUBMITTED)
            ->where('releases.data.0.extension.name', 'Older')
            ->where('releases.data.0.review.reasons.first_release', 'This is the extension\'s first release.')
            ->where('releases.data.1.status', Release::STATUS_IN_REVIEW)
            ->where("claimers.{$claimed->public_id}.name", 'Staff Reviewer')
            ->where("claimers.{$claimed->public_id}.id", $staff->public_id));

    $this->get('/review?tab=failed')
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'failed')->has('releases.data', 1)->where('releases.data.0.id', $failed->public_id));

    $this->get('/review?tab=decided')
        ->assertInertia(fn (Assert $page) => $page->where('tab', 'decided')->has('releases.data', 1)->where('releases.data.0.status', Release::STATUS_REJECTED)->where('releases.data.0.id', $rejected->public_id));

    $this->get('/review?tab=nonsense')->assertInertia(fn (Assert $page) => $page->where('tab', 'waiting'));
});

it('gives the release page the review items, checks and the listing preview', function () {
    $release = submittedRelease(extension: ['name' => 'Geotab Bridge', 'subtitle' => 'Trips in Fleet-Ops']);
    actingOnWebsite(staffAccount());

    $this->get('/review/releases/'.$release->public_id)
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Review/Release')
            ->has('reviewItems', 6)
            ->where('reviewItems.0', ['code' => 'R1', 'label' => 'Manifest is valid'])
            ->where('release.id', $release->public_id)
            ->where('release.status', Release::STATUS_SUBMITTED)
            ->where('release.extension.name', 'Geotab Bridge')
            ->where('release.extension.subtitle', 'Trips in Fleet-Ops')
            ->where('release.extension.status', Extension::STATUS_DRAFT)
            ->has('release.extension.organization.name')
            ->has('release.extension.price')
            ->has('release.extension.screenshots')
            ->has('release.checks', 8)
            ->where('release.checks.0.check', 'manifest')
            ->where('release.checks.0.passed', true)
            ->has('release.files.engine.size')
            ->has('release.reviews')
            ->where('suspension', null));

    $this->get('/review/releases/rel_missing')->assertNotFound();
});

it('shows the listing’s suspension on its release page', function () {
    $release = submittedRelease();
    $staff = staffAccount();
    app(ReleasePipeline::class)->suspend($release->extension, $staff, 'Leaks phone numbers.');
    actingOnWebsite($staff);

    $this->get('/review/releases/'.$release->public_id)
        ->assertInertia(fn (Assert $page) => $page
            ->where('release.extension.status', Extension::STATUS_SUSPENDED)
            ->where('suspension.reason', 'Leaks phone numbers.')
            ->where('suspension.by_name', 'Staff Reviewer'));
});

it('lists approved releases waiting to be published and suspended listings', function () {
    $approved = submittedRelease(extension: ['name' => 'Label Printer']);
    $suspended = submittedRelease(extension: ['name' => 'Quick SMS']);
    submittedRelease(extension: ['name' => 'Still waiting']);

    $staff = staffAccount();
    app(ReleasePipeline::class)->approve($approved, $staff, null, null, false);
    app(ReleasePipeline::class)->suspend($suspended->extension, $staff, 'Sends phone numbers to a third party.');
    actingOnWebsite($staff);

    $this->get('/review/publish')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Review/Pending')
            ->where('counts', ['waiting' => 2, 'publish' => 1, 'suspended' => 1])
            ->has('releases.data', 1)
            ->where('releases.data.0.id', $approved->public_id)
            ->where('releases.data.0.status', Release::STATUS_APPROVED)
            ->where('releases.data.0.extension.name', 'Label Printer')
            ->where("approvers.{$approved->public_id}.name", 'Staff Reviewer')
            ->has('suspended', 1)
            ->where('suspended.0.name', 'Quick SMS')
            ->where('suspended.0.slug', $suspended->extension->slug)
            ->where('suspended.0.suspension.reason', 'Sends phone numbers to a third party.')
            ->where('suspended.0.suspension.by_name', 'Staff Reviewer')
            ->has('suspended.0.organization.name'));
});

it('keeps the review console from publishers and signed-out visitors', function () {
    $release = submittedRelease();

    foreach (['/review', '/review/publish', '/review/releases/'.$release->public_id] as $url) {
        $this->get($url)->assertRedirect('/sign-in');
    }

    [$account] = publisher();
    actingOnWebsite($account);

    foreach (['/review', '/review/publish', '/review/releases/'.$release->public_id] as $url) {
        $this->get($url)
            ->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 403)->where('message', 'The review console is for Fleetbase staff.'));
    }
});
