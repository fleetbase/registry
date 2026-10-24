<?php

use App\Models\Event;
use App\Models\Extension;
use App\Models\File;
use App\Models\Release;
use App\Models\Token;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

function actingAsReviewer(): void
{
    actingAsAccount(staffAccount(), [Token::SCOPE_REVIEW]);
}

it('lists the review item codes and validates decisions against them', function () {
    Mail::fake();
    [$account, , $listing] = publisher();
    $id = uploadRelease($account, $listing, '1.0.0', [], ['submit' => '1'])->assertCreated()->json('id');
    actingAsReviewer();

    $this->getJson('/v1/admin/review-items')
        ->assertOk()
        ->assertExactJson([
            ['code' => 'R1', 'label' => 'Manifest is valid'],
            ['code' => 'R2', 'label' => 'Installs on a clean instance'],
            ['code' => 'R3', 'label' => 'Listing is complete'],
            ['code' => 'R4', 'label' => 'No secrets or credentials'],
            ['code' => 'R5', 'label' => 'Permissions go through the auth schema'],
            ['code' => 'R6', 'label' => 'Pricing matches Stripe Connect'],
        ]);

    $this->postJson('/v1/admin/releases/'.$id.'/reject', ['reason' => 'Needs work', 'items' => [['code' => 'R9', 'passed' => false]]])
        ->assertStatus(422)
        ->assertJsonStructure(['fields' => ['items.0.code']]);
    $this->postJson('/v1/admin/releases/'.$id.'/reject', ['reason' => 'Needs work', 'items' => [['code' => 'R6', 'passed' => false]]])->assertOk();

    actingAsAccount($account, [Token::SCOPE_ALL]);
    $this->getJson('/v1/admin/review-items')->assertForbidden();
});

it('downloads a release archive for staff and records it', function () {
    [$account, , $listing] = publisher();
    $id = uploadRelease($account, $listing, '1.0.0')->assertCreated()->json('id');
    $release = Release::findById($id);
    $engine = File::find($release->engine_tarball_file_uuid);
    actingAsReviewer();

    $response = $this->get('/v1/admin/releases/'.$id.'/files/engine')->assertOk();
    expect($response->headers->get('Content-Disposition'))->toBe('attachment; filename="'.$engine->name.'"')
        ->and($response->streamedContent())->toBe(Storage::disk('packages')->get($engine->path))
        ->and(Event::where('kind', 'release.downloaded')->sole()->payload)->toBe(['kind' => 'engine', 'file' => $engine->public_id]);

    $this->get('/v1/admin/releases/'.$id.'/files/api')->assertOk();
    $this->getJson('/v1/admin/releases/'.$id.'/files/dist')->assertNotFound()->assertExactJson(['errors' => ['Resource not found.']]);
    $this->getJson('/v1/admin/releases/'.$id.'/files/manifest')->assertNotFound()->assertExactJson(['errors' => ['Resource not found.']]);
    $this->getJson('/v1/admin/releases/rel_missing/files/engine')->assertNotFound();

    actingAsAccount($account, [Token::SCOPE_ALL]);
    $this->getJson('/v1/admin/releases/'.$id.'/files/engine')->assertForbidden();
});

it('unsuspends a listing back to the status it had', function () {
    Mail::fake();
    [$account, , $listing] = publisher();
    publishedRelease($account, $listing, '1.0.0');
    [, , $draft] = publisher();
    actingAsReviewer();

    $this->postJson('/v1/admin/extensions/'.$listing->slug.'/unsuspend')->assertStatus(409);

    $this->postJson('/v1/admin/extensions/'.$listing->slug.'/suspend', ['reason' => 'Malware report'])->assertOk()->assertJsonPath('status', 'suspended');
    $this->getJson('/v1/catalog/extensions/'.$listing->slug)->assertNotFound();

    $this->postJson('/v1/admin/extensions/'.$listing->slug.'/unsuspend', ['reason' => 'False alarm'])
        ->assertOk()
        ->assertJsonPath('status', Extension::STATUS_PUBLISHED)
        ->assertJsonPath('suspension', null);
    $this->getJson('/v1/catalog/extensions/'.$listing->slug)->assertOk();
    expect(Event::where('kind', 'extension.unsuspended')->sole()->payload)->toMatchArray(['reason' => 'False alarm', 'status' => 'published']);

    $this->postJson('/v1/admin/extensions/'.$draft->slug.'/suspend', ['reason' => 'Spam'])->assertOk();
    $this->postJson('/v1/admin/extensions/'.$draft->slug.'/unsuspend')->assertOk()->assertJsonPath('status', Extension::STATUS_DRAFT);

    // Suspended without a recorded status: published when it has a published release.
    $listing->forceFill(['status' => Extension::STATUS_SUSPENDED, 'meta' => null])->save();
    $this->postJson('/v1/admin/extensions/'.$listing->slug.'/unsuspend')->assertOk()->assertJsonPath('status', Extension::STATUS_PUBLISHED);
    $draft->forceFill(['status' => Extension::STATUS_SUSPENDED, 'meta' => null])->save();
    $this->postJson('/v1/admin/extensions/'.$draft->slug.'/unsuspend')->assertOk()->assertJsonPath('status', Extension::STATUS_DRAFT);
});

it('shows the listing preview in the review queue and release detail', function () {
    Mail::fake();
    [$account, $organization, $listing] = publisher(extension: ['promotional_text' => '<p>Rich overview</p>']);
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    test()->post('/v1/publisher/extensions/'.$listing->slug.'/icon', ['icon' => UploadedFile::fake()->image('logo.png')], ['Accept' => 'application/json'])->assertOk();
    test()->post('/v1/publisher/extensions/'.$listing->slug.'/screenshots', ['screenshot' => UploadedFile::fake()->image('a.png'), 'caption' => 'Main'], ['Accept' => 'application/json'])->assertCreated();
    $id = uploadRelease($account, $listing, '1.0.0', [], ['submit' => '1'])->assertCreated()->json('id');
    actingAsReviewer();

    $this->getJson('/v1/admin/releases')
        ->assertOk()
        ->assertJsonPath('data.0.extension.subtitle', $listing->subtitle)
        ->assertJsonPath('data.0.extension.promotional_text', '<p>Rich overview</p>')
        ->assertJsonPath('data.0.extension.screenshots.0.caption', 'Main');

    $this->getJson('/v1/admin/releases/'.$id)
        ->assertOk()
        ->assertJsonPath('extension.name', $listing->name)
        ->assertJsonPath('extension.description', $listing->description)
        ->assertJsonPath('extension.icon_url', fn ($url) => str_contains((string) $url, 'icons/'))
        ->assertJsonPath('extension.category.slug', $listing->category->slug)
        ->assertJsonPath('extension.tags', $listing->tags)
        ->assertJsonPath('extension.website_url', $listing->website_url)
        ->assertJsonPath('extension.organization.slug', $organization->slug)
        ->assertJsonPath('extension.price.model', 'free');
});

it('reaches the staff routes over the website session', function () {
    [$account, , $listing] = publisher();
    $id = uploadRelease($account, $listing, '1.0.0', [], ['submit' => '1'])->json('id');
    $staff = staffAccount();
    $staff->forceFill(['password' => 'password'])->save();
    config(['session.driver' => 'database']);
    $cookies = websiteSignIn($staff);

    websiteRequest('GET', '/v1/admin/releases/'.$id, $cookies)->assertOk();
    websiteRequest('POST', '/v1/admin/releases/'.$id.'/claim', $cookies, [], csrf: false)->assertStatus(419);
    websiteRequest('POST', '/v1/admin/releases/'.$id.'/claim', $cookies)->assertOk()->assertJsonPath('status', Release::STATUS_IN_REVIEW);
});
