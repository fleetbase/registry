<?php

use App\Models\Extension;
use App\Models\Licence;
use App\Models\Membership;
use App\Models\TrustedPublisher;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => Mail::fake());

it('renders Access for an owner: visibility, grants and trusted publishers', function () {
    [$account, , $extension] = publisher();
    $extension->forceFill(['visibility' => Extension::VISIBILITY_PRIVATE])->save();
    [$client, $instance] = clientOrganization('Client Co');
    accessGrant($extension, $client, ['max_activations' => 3, 'instance_uuids' => [$instance->uuid], 'meta' => ['note' => 'Pilot']]);
    [$former] = clientOrganization('Former Co');
    accessGrant($extension, $former, ['status' => Licence::STATUS_REVOKED]);
    $extension->trustedPublishers()->create([
        'created_by_account_uuid' => $account->uuid,
        'provider' => TrustedPublisher::PROVIDER_GITHUB,
        'repository' => 'acme/client-tools',
        'workflow' => 'release.yml',
        'refs' => ['refs/tags/v*'],
    ]);
    actingOnWebsite($account);

    $this->get("/studio/{$extension->slug}/access")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Access')
            ->where('forbidden', null)
            ->where('extension.slug', $extension->slug)
            ->where('extension.visibility', 'private')
            ->where('access.visibility', 'private')
            ->where('access.pending_visibility', null)
            ->has('access.grants', 2)
            ->where('access.grants', fn ($grants) => collect($grants)->contains(fn ($grant) => $grant['organization']['slug'] === $client->slug
                && $grant['status'] === 'active'
                && $grant['max_activations'] === 3
                && $grant['note'] === 'Pilot'
                && $grant['instances'][0]['id'] === $instance->public_id)
                && collect($grants)->contains(fn ($grant) => $grant['organization']['slug'] === $former->slug && $grant['status'] === 'revoked'))
            ->has('trusted_publishers', 1)
            ->where('trusted_publishers.0.repository', 'acme/client-tools')
            ->where('trusted_publishers.0.refs', ['refs/tags/v*']));
});

it('renders Access for a public listing without grants', function () {
    [$account, , $extension] = publisher(Membership::ROLE_ADMIN);
    actingOnWebsite($account);

    $this->get("/studio/{$extension->slug}/access")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Access')
            ->where('access.visibility', 'public')
            ->has('access.grants', 0)
            ->has('trusted_publishers', 0)
            ->where('extension.visibility', 'public'));
});

it('tells a publisher without the owner or admin role who can manage access', function () {
    [$account, , $extension] = publisher(Membership::ROLE_PUBLISHER);
    actingOnWebsite($account);

    $this->get("/studio/{$extension->slug}/access")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Studio/Access')
            ->where('extension.slug', $extension->slug)
            ->where('access', null)
            ->where('trusted_publishers', [])
            ->where('forbidden', fn ($message) => is_string($message) && $message !== ''));
});

it('answers 404 for a listing of an organization the account is not a member of', function () {
    [$account] = publisher();
    [, , $theirs] = publisher();
    $theirs->forceFill(['visibility' => Extension::VISIBILITY_PRIVATE])->save();
    actingOnWebsite($account);

    $this->get("/studio/{$theirs->slug}/access")
        ->assertNotFound()
        ->assertInertia(fn (Assert $page) => $page->component('Error')->where('status', 404));
});

it('sends a signed-out visitor to sign in', function () {
    [, , $extension] = publisher();

    $this->get("/studio/{$extension->slug}/access")->assertRedirect('/sign-in');
});
