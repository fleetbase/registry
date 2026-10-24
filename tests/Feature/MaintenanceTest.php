<?php

use App\Models\Extension;
use App\Models\Instance;
use App\Models\Release;
use App\Models\Token;
use App\Support\Maintenance;
use Illuminate\Testing\TestResponse;

function assertFrozen(TestResponse $response, string $message): void
{
    $response->assertStatus(503)
        ->assertJsonPath('errors.0', $message)
        ->assertJsonPath('maintenance', true)
        ->assertHeader('Retry-After', '3600');
}

it('freezes publishing and checkout while maintenance is on, and nothing else', function () {
    [$account, , $listing] = publisher();
    $default = config('registry.maintenance.message');

    $this->artisan('registry:maintenance', ['action' => 'on'])->expectsOutputToContain('Maintenance: ON')->assertSuccessful();

    actingAsAccount($account, [Token::SCOPE_ALL]);
    assertFrozen($this->postJson('/v1/publisher/extensions', ['name' => 'New thing']), $default);
    assertFrozen($this->patchJson("/v1/publisher/extensions/{$listing->slug}", ['subtitle' => 'x']), $default);
    assertFrozen($this->postJson("/v1/publisher/extensions/{$listing->slug}/releases", []), $default);
    assertFrozen($this->postJson('/v1/purchases/checkout', ['extension' => $listing->slug]), $default);
    $this->getJson('/v1/publisher/extensions')->assertOk();
    $this->getJson("/v1/publisher/extensions/{$listing->slug}")->assertOk();

    actingAsAccount(staffAccount());
    $release = Release::factory()->create(['extension_uuid' => $listing->uuid, 'status' => Release::STATUS_IN_REVIEW]);
    assertFrozen($this->postJson("/v1/admin/releases/{$release->public_id}/approve", []), $default);
    assertFrozen($this->postJson("/v1/admin/releases/{$release->public_id}/publish", []), $default);
    $this->getJson('/v1/admin/releases')->assertOk();

    actingAsInstance(Instance::factory()->create());
    assertFrozen($this->postJson('/v1/instances/me/purchases/checkout', ['extension' => $listing->slug, 'return_url' => 'https://console.example/x']), $default);
    $this->getJson('/v1/instances/me')->assertOk();

    // Catalog, downloads and Stripe webhooks for checkouts already paid keep working.
    $this->getJson('/v1/catalog/extensions')->assertOk();
    $this->call('POST', '/v1/webhooks/stripe', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{}')
        ->assertJsonMissingPath('maintenance');

    $this->artisan('registry:maintenance', ['action' => 'off'])->expectsOutputToContain('Maintenance: off')->assertSuccessful();
    actingAsAccount($account, [Token::SCOPE_ALL]);
    $this->patchJson("/v1/publisher/extensions/{$listing->slug}", ['subtitle' => 'Back on'])->assertOk();
    expect(Extension::find($listing->uuid)->subtitle)->toBe('Back on');
});

it('shows the message given when maintenance was turned on', function () {
    [$account, , $listing] = publisher();

    $this->artisan('registry:maintenance', ['action' => 'on', '--message' => 'Back at 14:00 UTC.'])
        ->expectsOutputToContain('Message: Back at 14:00 UTC.')->assertSuccessful();

    actingAsAccount($account, [Token::SCOPE_ALL]);
    assertFrozen($this->patchJson("/v1/publisher/extensions/{$listing->slug}", ['subtitle' => 'x']), 'Back at 14:00 UTC.');
    expect(Maintenance::status())->toMatchArray(['active' => true, 'forced' => false, 'message' => 'Back at 14:00 UTC.']);
});

it('stays on while REGISTRY_MAINTENANCE forces it', function () {
    config(['registry.maintenance.enabled' => true]);
    [$account, , $listing] = publisher();

    $this->artisan('registry:maintenance', ['action' => 'off'])
        ->expectsOutputToContain('REGISTRY_MAINTENANCE=true still forces it on')
        ->assertFailed();

    actingAsAccount($account, [Token::SCOPE_ALL]);
    $this->patchJson("/v1/publisher/extensions/{$listing->slug}", ['subtitle' => 'x'])->assertStatus(503);
});

it('rejects an unknown maintenance action', function () {
    $this->artisan('registry:maintenance', ['action' => 'maybe'])->assertExitCode(2);
    expect(Maintenance::active())->toBeFalse();
});
