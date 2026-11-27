<?php

use App\Models\Category;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Membership;
use App\Models\Token;

/*
 * extension.json's `listing` block: `flb publish` applies it with PUT …/listing-manifest. The fields it
 * sets win over Studio (`meta.manifest_fields`): PATCH refuses to change them, and fields the block
 * leaves out stay editable.
 */

beforeEach(function () {
    [$this->account, $this->organization, $this->extension] = publisher();
    actingAsAccount($this->account, [Token::SCOPE_PUBLISH]);
    $this->category = Category::factory()->create(['slug' => 'telematics']);
    $this->path = '/v1/publisher/extensions/'.$this->extension->slug;
});

it('applies the listing block and records the fields it manages', function () {
    $this->putJson($this->path.'/listing-manifest', [
        'subtitle' => 'LocoNav telematics for Fleet-Ops',
        'category' => 'telematics',
        'tags' => ['telematics', 'loconav'],
        'fa_icon' => 'satellite-dish',
        'repo_url' => 'https://github.com/fleetbase/loconav',
        'hosting' => Extension::HOSTING_BOTH,
    ])->assertOk()
        ->assertJsonPath('subtitle', 'LocoNav telematics for Fleet-Ops')
        ->assertJsonPath('category.slug', 'telematics')
        ->assertJsonPath('tags', ['telematics', 'loconav'])
        ->assertJsonPath('fa_icon', 'satellite-dish')
        ->assertJsonPath('manifest_fields', ['subtitle', 'category', 'tags', 'hosting', 'repo_url', 'fa_icon']);

    expect($this->extension->fresh()->meta['manifest_fields'])->toBe(['subtitle', 'category', 'tags', 'hosting', 'repo_url', 'fa_icon'])
        ->and(Event::where('kind', 'extension.manifest_listing_applied')->sole()->payload['fields'])->toHaveCount(6);
    $this->getJson($this->path)->assertOk()->assertJsonPath('manifest_fields.0', 'subtitle');
});

it('validates the block like a listing edit', function () {
    $this->putJson($this->path.'/listing-manifest', ['support_url' => 'not a url', 'fa_icon' => 'Bad Icon'])
        ->assertStatus(422)->assertJsonValidationErrors(['support_url', 'fa_icon'], 'fields');
    $this->putJson($this->path.'/listing-manifest', ['category' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('category', 'fields');

    expect($this->extension->fresh()->manifestFields())->toBe([]);
});

it('refuses Studio edits to fields the manifest manages and allows the others', function () {
    $this->putJson($this->path.'/listing-manifest', ['subtitle' => 'From the manifest', 'category' => 'telematics', 'tags' => ['a']])->assertOk();

    $this->patchJson($this->path, ['subtitle' => 'From Studio', 'tags' => ['b'], 'copyright' => 'Acme'])
        ->assertStatus(422)
        ->assertJsonPath('fields.subtitle.0', 'Set in extension.json; change it there and publish.')
        ->assertJsonPath('fields.tags.0', 'Set in extension.json; change it there and publish.')
        ->assertJsonMissingPath('fields.copyright');
    expect($this->extension->fresh()->subtitle)->toBe('From the manifest');

    // The current values are fine (a form that posts everything), and unmanaged fields save.
    $this->patchJson($this->path, ['subtitle' => 'From the manifest', 'category' => 'telematics', 'tags' => ['a'], 'copyright' => 'Acme'])
        ->assertOk()->assertJsonPath('copyright', 'Acme');
});

it('hands fields back to Studio when the block no longer sets them', function () {
    $this->putJson($this->path.'/listing-manifest', ['subtitle' => 'Managed', 'copyright' => 'Acme'])->assertOk();
    $this->putJson($this->path.'/listing-manifest', ['copyright' => 'Acme'])->assertOk()->assertJsonPath('manifest_fields', ['copyright']);

    // The value stays; only who manages it changes.
    expect($this->extension->fresh()->subtitle)->toBe('Managed');
    $this->patchJson($this->path, ['subtitle' => 'Studio now'])->assertOk()->assertJsonPath('subtitle', 'Studio now');

    $this->putJson($this->path.'/listing-manifest', [])->assertOk()->assertJsonPath('manifest_fields', []);
    expect($this->extension->fresh()->meta['manifest_fields'] ?? null)->toBeNull();
    expect(Event::where('kind', 'extension.manifest_listing_applied')->latest('id')->first()->payload['released'])->toBe(['copyright']);
});

it('never takes the price, visibility or name from the manifest', function () {
    $this->putJson($this->path.'/listing-manifest', ['name' => 'Renamed', 'visibility' => Extension::VISIBILITY_PRIVATE, 'price' => ['model' => 'one_time'], 'subtitle' => 'x'])
        ->assertOk()->assertJsonPath('manifest_fields', ['subtitle']);

    $fresh = $this->extension->fresh();
    expect($fresh->name)->not->toBe('Renamed')
        ->and($fresh->visibility ?? Extension::VISIBILITY_PUBLIC)->toBe(Extension::VISIBILITY_PUBLIC);
});

it('keeps the listing block to members who may publish for the organization', function () {
    [$other] = publisher(Membership::ROLE_OWNER);
    actingAsAccount($other, [Token::SCOPE_PUBLISH]);
    $this->putJson($this->path.'/listing-manifest', ['subtitle' => 'x'])->assertNotFound();
});

it('lets a trusted publishing token apply the listing block, but not edit the listing otherwise', function () {
    fakeGithubJwks();
    $this->extension->trustedPublishers()->create([
        'created_by_account_uuid' => $this->account->uuid,
        'repository' => 'Acme/Secret-Ext',
        'workflow' => 'release.yml',
        'refs' => ['refs/tags/v*'],
    ]);
    $plain = $this->postJson('/v1/auth/oidc/github', ['token' => githubToken(), 'extension' => $this->extension->slug])->assertOk()->json('token');
    app('auth')->forgetGuards();
    $headers = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];

    $this->putJson($this->path.'/listing-manifest', ['subtitle' => 'From CI'], $headers)->assertOk()->assertJsonPath('manifest_fields', ['subtitle']);
    $this->patchJson($this->path, ['copyright' => 'x'], $headers)->assertUnauthorized();
    $this->putJson($this->path.'/price', ['model' => 'free'], $headers)->assertUnauthorized();
});

it('applies highlights and setup steps from the listing block and validates them', function () {
    $this->putJson($this->path.'/listing-manifest', [
        'highlights' => ['Live vehicle positions on the Fleet-Ops map', 'Trips and fuel from LocoNav'],
        'setup_steps' => ['Open Settings → LocoNav', 'Paste your API key'],
    ])->assertOk()
        ->assertJsonPath('highlights', ['Live vehicle positions on the Fleet-Ops map', 'Trips and fuel from LocoNav'])
        ->assertJsonPath('setup_steps', ['Open Settings → LocoNav', 'Paste your API key'])
        ->assertJsonPath('manifest_fields', ['highlights', 'setup_steps']);

    // Studio may not change them now, but may resend the same lines.
    $this->patchJson($this->path, ['highlights' => ['Something else']])->assertStatus(422)
        ->assertJsonPath('fields.highlights.0', 'Set in extension.json; change it there and publish.');
    $this->patchJson($this->path, ['setup_steps' => ['Open Settings → LocoNav', 'Paste your API key']])->assertOk();

    $this->putJson($this->path.'/listing-manifest', [
        'highlights' => array_fill(0, 6, 'Too many'),
        'setup_steps' => [str_repeat('x', 121), ''],
    ])->assertStatus(422)->assertJsonValidationErrors(['highlights', 'setup_steps.0', 'setup_steps.1'], 'fields');
});

it('edits highlights and setup steps from Studio and shows them in the catalog', function () {
    $this->extension->update(['status' => Extension::STATUS_PUBLISHED]);

    $this->patchJson($this->path, ['highlights' => ['Maps', 'Alerts'], 'setup_steps' => ['Connect your account']])->assertOk()
        ->assertJsonPath('highlights', ['Maps', 'Alerts'])
        ->assertJsonPath('setup_steps', ['Connect your account']);

    $this->getJson('/v1/catalog/extensions/'.$this->extension->slug)->assertOk()
        ->assertJsonPath('highlights', ['Maps', 'Alerts'])
        ->assertJsonPath('setup_steps', ['Connect your account']);

    // An empty list clears it.
    $this->patchJson($this->path, ['setup_steps' => []])->assertOk()->assertJsonPath('setup_steps', []);
    expect($this->extension->fresh()->setup_steps)->toBeNull();
});
