<?php

use App\Models\Extension;
use App\Models\ExtensionPackage;
use App\Models\Membership;
use App\Models\Release;
use App\Releases\ReleasePipeline;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Mail::fake());

/**
 * Upload a release as the publisher (token auth), then sign the publisher in to the website.
 */
function studioRelease($account, $extension, string $version, array $fields = []): Release
{
    $id = uploadRelease($account, $extension, $version, [], $fields)->assertCreated()->json('id');
    test()->flushHeaders();

    return Release::findById($id);
}

it('renders My extensions with every listing, its releases and readiness', function () {
    [$account, $organization, $extension] = publisher();
    studioRelease($account, $extension, '0.1.0');
    actingOnWebsite($account);

    $this->get("/studio?org={$organization->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Index')
            ->where('organization', $organization->slug)
            ->has('extensions', 1)
            ->where('extensions.0.slug', $extension->slug)
            ->where('extensions.0.organization.slug', $organization->slug)
            ->where('extensions.0.readiness.ready', true)
            ->has('extensions.0.releases', 1)
            ->where('extensions.0.releases.0.version', '0.1.0')
            ->where('extensions.0.releases.0.status', Release::STATUS_UPLOADED)
            ->has('extensions.0.price')
            ->where('auth.account.organizations.0.role', 'owner'));
});

it('shows no other publisher’s listings', function () {
    [$account] = publisher();
    publisher();
    actingOnWebsite($account);

    $this->get('/studio')->assertInertia(fn (Assert $page) => $page->component('Studio/Index')->has('extensions', 1)->where('organization', null));
});

it('renders the create page with categories', function () {
    [$account] = publisher();
    actingOnWebsite($account);

    $this->get('/studio/new')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Studio/Create')->has('categories'));
});

it('renders the editor with the listing, its releases and the categories', function () {
    [$account, , $extension] = publisher(extension: ['subtitle' => null]);
    studioRelease($account, $extension, '0.1.0');
    actingOnWebsite($account);

    $this->get("/studio/{$extension->slug}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Editor')
            ->where('extension.slug', $extension->slug)
            ->where('extension.readiness.ready', false)
            ->where('extension.readiness.missing', ['The listing needs a subtitle.'])
            ->has('extension.screenshots', 0)
            ->where('extension.price.model', 'free')
            ->has('releases', 1)
            ->where('releases.0.version', '0.1.0')
            ->has('releases.0.checks')
            ->has('categories'));
});

it('renders the releases page and one release with its checks and files', function () {
    [$account, , $extension] = publisher();
    $release = studioRelease($account, $extension, '0.1.0', ['notes' => 'First cut.']);
    actingOnWebsite($account);

    $this->get("/studio/{$extension->slug}/releases")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Releases')
            ->where('extension.slug', $extension->slug)
            ->has('releases', 1)
            ->where('releases.0.id', $release->public_id)
            ->has('releases.0.files.engine.size'));

    $this->get("/studio/{$extension->slug}/releases/{$release->public_id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Release')
            ->where('extension.slug', $extension->slug)
            ->where('release.id', $release->public_id)
            ->where('release.version', '0.1.0')
            ->where('release.status', Release::STATUS_UPLOADED)
            ->where('release.notes', 'First cut.')
            ->has('release.checks', 8)
            ->has('release.reviews'));
});

it('picks the open release as the review candidate', function () {
    [$account, , $extension] = publisher();
    actingOnWebsite($account);

    $this->get("/studio/{$extension->slug}/review")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Studio/Review')->where('candidate', null)->has('releases', 0)->where('extension.slug', $extension->slug));

    publishedRelease($account, $extension, '0.1.0');
    test()->flushHeaders();
    actingOnWebsite($account);
    $this->get("/studio/{$extension->slug}/review")->assertInertia(fn (Assert $page) => $page->where('candidate', null)->has('releases', 1));

    $open = studioRelease($account, $extension, '0.2.0');
    actingOnWebsite($account);
    $this->get("/studio/{$extension->slug}/review")
        ->assertInertia(fn (Assert $page) => $page
            ->has('releases', 2)
            ->where('candidate.id', $open->public_id)
            ->where('candidate.status', Release::STATUS_UPLOADED)
            ->has('candidate.review.reasons')
            ->has('candidate.reviews'));
});

it('gives a rejected candidate its reviewer decision and R-items', function () {
    [$account, , $extension] = publisher();
    $release = studioRelease($account, $extension, '0.1.0', ['submit' => '1']);
    expect($release->fresh()->status)->toBe(Release::STATUS_SUBMITTED);
    $staff = staffAccount();
    app(ReleasePipeline::class)->reject($release->fresh(), $staff, 'A key is in config.', [['code' => 'R4', 'passed' => false, 'note' => 'config/yard.php line 22'], ['code' => 'R1', 'passed' => true]]);
    actingOnWebsite($account);

    $this->get("/studio/{$extension->slug}/review")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Review')
            ->where('candidate.id', $release->public_id)
            ->where('candidate.status', Release::STATUS_REJECTED)
            ->where('candidate.reviews', fn ($reviews) => collect($reviews)->contains(fn ($review) => $review['action'] === 'reject'
                && $review['reason'] === 'A key is in config.'
                && $review['by']['name'] === 'Staff Reviewer'
                && collect($review['items'])->contains(fn ($item) => $item['code'] === 'R4' && $item['passed'] === false))));
});

it('renders the history with each release’s reviews', function () {
    [$account, , $extension] = publisher();
    $first = studioRelease($account, $extension, '0.1.0', ['submit' => '1']);
    app(ReleasePipeline::class)->reject($first->fresh(), staffAccount(), 'Not yet.', [['code' => 'R2', 'passed' => false]]);
    $second = studioRelease($account, $extension, '0.2.0');
    actingOnWebsite($account);

    $this->get("/studio/{$extension->slug}/history")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/History')
            ->where('extension.slug', $extension->slug)
            ->where('truncated', false)
            ->has('releases', 2)
            ->where('releases.0.id', $second->public_id)
            ->where('releases.1.id', $first->public_id)
            ->where('releases.0.reviews', fn ($reviews) => collect($reviews)->pluck('action')->contains('upload'))
            ->where('releases.1.reviews', fn ($reviews) => collect($reviews)->pluck('action')->intersect(['upload', 'submit', 'reject'])->count() === 3));
});

it('renders insights for all of the organization\'s listings, then for one', function () {
    $this->travelTo(Carbon::parse('2026-10-23 12:00:00'));
    [$account, $organization, $listing, [$v1]] = statsListing();
    statsInstall($listing, $v1, '2026-10-20 10:00:00');
    actingOnWebsite($account);

    $this->get('/studio/insights')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Insights')
            ->where('organization.slug', $organization->slug)
            ->where('extensions', [['id' => $listing->public_id, 'slug' => $listing->slug, 'name' => $listing->name, 'status' => 'published', 'fa_icon' => $listing->fa_icon, 'icon_url' => null]])
            ->where('extension', null)
            ->where('stats.organization.slug', $organization->slug)
            ->where('stats.range.from', '2026-09-24')
            ->where('stats.totals.installs', 1)
            ->where('stats.extensions.0.extension.slug', $listing->slug)
            ->missing('stats.versions'));

    $this->get("/studio/insights/{$organization->slug}?extension={$listing->slug}&from=2026-10-01&to=2026-10-23&group=week")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Insights')
            ->where('extension', $listing->slug)
            ->where('filters', ['from' => '2026-10-01', 'to' => '2026-10-23', 'group' => 'week'])
            ->where('stats.extension.slug', $listing->slug)
            ->where('stats.range.group', 'week')
            ->where('stats.versions.0.version', '1.0.0')
            ->where('stats.events.data.0.type', 'install'));
});

it('shows why a range was refused instead of failing the page', function () {
    [$account, $organization] = statsListing();
    actingOnWebsite($account);

    $this->get("/studio/insights/{$organization->slug}?from=2026-10-10&to=2026-10-01")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats', null)->where('error', 'The start of the range must be on or before its end.'));
});

it('answers 404 on insights for a listing or organization that is not the publisher\'s', function () {
    [$account, $organization] = statsListing();
    [, $theirs, $listing] = statsListing();
    actingOnWebsite($account);

    $this->get("/studio/insights/{$organization->slug}?extension={$listing->slug}")->assertNotFound();
    $this->get("/studio/insights/{$theirs->slug}")->assertNotFound();
});

it('leaves out organizations where the account is only a billing member', function () {
    [$account, $organization] = statsListing(Membership::ROLE_BILLING);
    actingOnWebsite($account);

    $this->get('/studio/insights')->assertOk()->assertInertia(fn (Assert $page) => $page->where('organizations', [])->where('organization', null)->where('stats', null));
});

it('answers 404 for another publisher’s listing and release', function () {
    [$account] = publisher();
    [$other, , $theirs] = publisher();
    $release = studioRelease($other, $theirs, '0.1.0');
    actingOnWebsite($account);

    foreach (['', '/releases', '/review', '/history', "/releases/{$release->public_id}"] as $path) {
        $this->get("/studio/{$theirs->slug}{$path}")->assertNotFound()->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
    }
});

it('answers 404 for a release of another listing under this listing’s slug', function () {
    [$account, $organization, $mine] = publisher();
    $sibling = Extension::factory()->listingReady()->free()->for($organization)->create();
    $sibling->packages()->createMany([
        ['kind' => ExtensionPackage::KIND_NPM, 'name' => '@'.$organization->slug.'/'.$sibling->slug.'-engine'],
        ['kind' => ExtensionPackage::KIND_COMPOSER, 'name' => $organization->slug.'/'.$sibling->slug.'-api'],
    ]);
    $release = studioRelease($account, $sibling, '0.1.0');
    actingOnWebsite($account);

    $this->get("/studio/{$sibling->slug}/releases/{$release->public_id}")->assertOk();
    $this->get("/studio/{$mine->slug}/releases/{$release->public_id}")->assertNotFound();
});

it('sends signed-out visitors to sign in', function () {
    [, , $extension] = publisher();

    foreach (['/studio', '/studio/new', '/studio/insights', "/studio/{$extension->slug}", "/studio/{$extension->slug}/releases", "/studio/{$extension->slug}/releases/rel_x", "/studio/{$extension->slug}/review", "/studio/{$extension->slug}/history"] as $path) {
        $this->get($path)->assertRedirect('/sign-in');
    }
});
