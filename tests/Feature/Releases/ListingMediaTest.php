<?php

use App\Models\Event;
use App\Models\Extension;
use App\Models\File;
use App\Models\Membership;
use App\Models\Token;
use App\Releases\ListingReadiness;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

function mediaUpload(string $path, array $fields)
{
    return test()->post($path, $fields, ['Accept' => 'application/json']);
}

it('uploads, replaces and removes the listing icon', function () {
    [$account, , $listing] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $path = '/v1/publisher/extensions/'.$listing->slug.'/icon';

    $first = mediaUpload($path, ['icon' => UploadedFile::fake()->image('logo.png', 128, 128)])
        ->assertOk()
        ->assertJsonPath('icon_url', fn ($url) => is_string($url) && str_contains($url, 'icons/'))
        ->json('icon_url');

    $icon = File::where('type', File::TYPE_ICON)->sole();
    expect($listing->fresh()->icon_file_uuid)->toBe($icon->uuid)
        ->and($icon->mime)->toBe('image/png')
        ->and($icon->subject_uuid)->toBe($listing->uuid)
        ->and(Storage::disk('packages')->exists($icon->path))->toBeTrue();

    // A new icon replaces the old one and deletes its object.
    mediaUpload($path, ['icon' => UploadedFile::fake()->image('logo.webp', 64, 64)])->assertOk()->assertJsonPath('icon_url', fn ($url) => $url !== $first);
    expect(File::where('type', File::TYPE_ICON)->count())->toBe(1)
        ->and(Storage::disk('packages')->exists($icon->path))->toBeFalse();

    $this->deleteJson($path)->assertOk()->assertJsonPath('icon_url', null);
    expect($listing->fresh()->icon_file_uuid)->toBeNull()
        ->and(File::where('type', File::TYPE_ICON)->count())->toBe(0)
        ->and(Event::where('kind', 'extension.icon_updated')->count())->toBe(2)
        ->and(Event::where('kind', 'extension.icon_removed')->count())->toBe(1);
});

it('accepts only small PNG, JPEG and WebP icons', function () {
    [$account, , $listing] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $path = '/v1/publisher/extensions/'.$listing->slug.'/icon';

    $svg = fakeUpload('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'icon.svg');
    mediaUpload($path, ['icon' => $svg])->assertStatus(422)->assertJsonPath('fields.icon.0', 'The icon field must be a file of type: png, jpg, jpeg, webp.');
    mediaUpload($path, ['icon' => fakeUpload('not an image', 'icon.png')])->assertStatus(422);
    mediaUpload($path, ['icon' => UploadedFile::fake()->image('big.png')->size(1100)])->assertStatus(422);
    mediaUpload($path, [])->assertStatus(422);

    expect(File::count())->toBe(0);
});

it('counts an uploaded icon for listing readiness', function () {
    [$account, , $listing] = publisher(extension: ['fa_icon' => null]);
    expect(app(ListingReadiness::class)->errors($listing))->toContain('The listing needs an icon.');

    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    mediaUpload('/v1/publisher/extensions/'.$listing->slug.'/icon', ['icon' => UploadedFile::fake()->image('logo.png')])
        ->assertOk()
        ->assertJsonPath('readiness.ready', true);
});

it('adds, orders and removes screenshots, at most eight', function () {
    [$account, , $listing] = publisher();
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $path = '/v1/publisher/extensions/'.$listing->slug.'/screenshots';

    mediaUpload($path, ['screenshot' => UploadedFile::fake()->image('a.png', 800, 600), 'caption' => 'Dashboard'])
        ->assertCreated()
        ->assertJsonPath('screenshots.0.caption', 'Dashboard')
        ->assertJsonPath('screenshots.0.order', 0);
    mediaUpload($path, ['screenshot' => UploadedFile::fake()->image('b.jpg', 800, 600)])->assertCreated();
    $ids = mediaUpload($path, ['screenshot' => UploadedFile::fake()->image('c.webp', 800, 600), 'caption' => 'Reports'])
        ->assertCreated()
        ->json('screenshots.*.id');

    expect($ids)->toHaveCount(3);

    $this->patchJson($path, ['order' => [$ids[2], $ids[0], $ids[1]]])
        ->assertOk()
        ->assertJsonPath('screenshots.*.id', [$ids[2], $ids[0], $ids[1]])
        ->assertJsonPath('screenshots.0.caption', 'Reports')
        ->assertJsonPath('screenshots.*.order', [0, 1, 2]);
    $this->patchJson($path, ['order' => [$ids[0], $ids[1]]])->assertStatus(422);
    $this->patchJson($path, ['order' => [$ids[0], $ids[0], $ids[1]]])->assertStatus(422);
    $this->patchJson($path, ['order' => [$ids[0], $ids[1], 'file_other']])->assertStatus(422);

    $this->deleteJson($path.'/'.$ids[0])->assertOk()->assertJsonPath('screenshots.*.id', [$ids[2], $ids[1]]);
    $this->deleteJson($path.'/'.$ids[0])->assertNotFound();

    // A new screenshot goes last.
    $last = mediaUpload($path, ['screenshot' => UploadedFile::fake()->image('d.png')])->assertCreated()->json('screenshots.2.id');
    expect($last)->not->toBeIn($ids);

    foreach (range(1, 5) as $i) {
        mediaUpload($path, ['screenshot' => UploadedFile::fake()->image("e{$i}.png")])->assertCreated();
    }
    mediaUpload($path, ['screenshot' => UploadedFile::fake()->image('f.png')])
        ->assertStatus(422)
        ->assertJsonPath('fields.screenshot.0', 'A listing can have at most 8 screenshots. Delete one first.');
    mediaUpload($path, ['screenshot' => UploadedFile::fake()->image('big.png')->size(6000)])->assertStatus(422);
    mediaUpload($path, ['screenshot' => fakeUpload('<svg/>', 's.svg')])->assertStatus(422);
});

it('keeps listing media to the listing\'s publishers', function () {
    [, , $listing] = publisher();
    [$stranger] = publisher();
    [$member, $organization] = publisher(Membership::ROLE_MEMBER);
    $member->organizations()->attach($listing->organization_uuid, ['role' => Membership::ROLE_MEMBER]);

    actingAsAccount($stranger, [Token::SCOPE_PUBLISH]);
    mediaUpload('/v1/publisher/extensions/'.$listing->slug.'/icon', ['icon' => UploadedFile::fake()->image('logo.png')])->assertNotFound();

    actingAsAccount($member, [Token::SCOPE_PUBLISH]);
    mediaUpload('/v1/publisher/extensions/'.$listing->slug.'/screenshots', ['screenshot' => UploadedFile::fake()->image('a.png')])->assertNotFound();

    [$owner, , $own] = publisher();
    actingAsAccount($owner, [Token::SCOPE_READ]);
    mediaUpload('/v1/publisher/extensions/'.$own->slug.'/icon', ['icon' => UploadedFile::fake()->image('logo.png')])->assertForbidden();
});

it('shows the icon and ordered screenshots in the public catalog', function () {
    [$account, , $listing] = publisher(extension: ['status' => Extension::STATUS_PUBLISHED, 'published_at' => now()]);
    actingAsAccount($account, [Token::SCOPE_PUBLISH]);
    $base = '/v1/publisher/extensions/'.$listing->slug;

    mediaUpload($base.'/icon', ['icon' => UploadedFile::fake()->image('logo.png')])->assertOk();
    $first = mediaUpload($base.'/screenshots', ['screenshot' => UploadedFile::fake()->image('a.png'), 'caption' => 'First'])->json('screenshots.0.id');
    $second = mediaUpload($base.'/screenshots', ['screenshot' => UploadedFile::fake()->image('b.png'), 'caption' => 'Second'])->json('screenshots.1.id');
    $this->patchJson($base.'/screenshots', ['order' => [$second, $first]])->assertOk();

    $this->withHeaders(['Authorization' => ''])->getJson('/v1/catalog/extensions/'.$listing->slug)
        ->assertOk()
        ->assertJsonPath('icon_url', fn ($url) => str_contains((string) $url, 'icons/'))
        ->assertJsonPath('screenshots.*.id', [$second, $first])
        ->assertJsonPath('screenshots.0.caption', 'Second')
        ->assertJsonPath('screenshots.0.order', 0);
    $this->getJson('/v1/catalog/extensions')->assertJsonPath('data.0.icon_url', fn ($url) => str_contains((string) $url, 'icons/'));
});
