<?php

use App\Models\Account;
use App\Models\Extension;
use App\Models\Membership;
use App\Models\Release;
use App\Models\Token;
use App\Models\TrustedPublisher;

/*
 * Trusted publishing: a GitHub Actions OIDC token, verified against GitHub's JWKS and matched
 * against the extension's trusted publishers, is exchanged for a short-lived `flbt_` token that
 * only reaches that extension's release routes.
 */

beforeEach(function () {
    fakeGithubJwks();
    [$this->account, $this->organization, $this->extension] = publisher(extension: ['visibility' => Extension::VISIBILITY_PRIVATE]);
    $this->publisher = $this->extension->trustedPublishers()->create([
        'created_by_account_uuid' => $this->account->uuid,
        'repository' => 'Acme/Secret-Ext',
        'workflow' => 'release.yml',
        'refs' => ['refs/tags/v*'],
    ]);
});

function exchange(string $jwt, string $extension)
{
    return test()->postJson('/v1/auth/oidc/github', ['token' => $jwt, 'extension' => $extension]);
}

it('exchanges a matching OIDC token for a short-lived publish token limited to the extension', function () {
    $response = exchange(githubToken(), $this->extension->slug)->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('scopes', ['publish'])
        ->assertJsonPath('extension.slug', $this->extension->slug)
        ->assertJsonPath('trusted_publisher.id', $this->publisher->public_id)
        ->assertHeader('Cache-Control', 'no-store, private');

    $plain = $response->json('token');
    expect($plain)->toStartWith('flbt_');
    $token = Token::findActive($plain);
    expect($token->extension_uuid)->toBe($this->extension->uuid)
        ->and($token->organization_uuid)->toBe($this->organization->uuid)
        ->and($token->expires_at->between(now()->addMinutes(14), now()->addMinutes(16)))->toBeTrue();

    // first use pins the repository and owner ids
    expect($this->publisher->fresh()->repository_id)->toBe('123456')
        ->and($this->publisher->fresh()->repository_owner_id)->toBe('7890')
        ->and($this->publisher->fresh()->last_used_at)->not->toBeNull();

    // a package name identifies the extension too
    exchange(githubToken(), packageName($this->extension, 'npm'))->assertOk();
});

it('publishes with the exchanged token, auto-publishing a private release', function () {
    $plain = exchange(githubToken(), packageName($this->extension, 'npm'))->json('token');
    $headers = ['Authorization' => 'Bearer '.$plain, 'Accept' => 'application/json'];

    $listings = $this->getJson('/v1/publisher/extensions', $headers)->assertOk();
    expect(collect($listings->json())->pluck('slug')->all())->toBe([$this->extension->slug]);

    $id = $this->post('/v1/publisher/extensions/'.$this->extension->slug.'/releases', releaseFiles($this->extension, '1.2.0') + ['submit' => '1'], $headers)
        ->assertCreated()->json('id');
    $this->getJson('/v1/publisher/releases/'.$id, $headers)->assertOk()->assertJsonPath('status', Release::STATUS_PUBLISHED);

    $release = Release::findById($id);
    expect($release->created_by_account_uuid)->toBe($this->account->uuid)
        ->and($release->metaValue('trusted_publisher.id'))->toBe($this->publisher->public_id);
});

it('keeps the exchanged token off every other route and extension', function () {
    $plain = exchange(githubToken(), $this->extension->slug)->json('token');
    $headers = ['Authorization' => 'Bearer '.$plain];

    // another listing of the same organization
    $other = Extension::factory()->for($this->organization)->create(['slug' => 'other-ext']);
    $this->getJson('/v1/publisher/extensions/other-ext', $headers)->assertNotFound();
    $this->post('/v1/publisher/extensions/other-ext/releases', releaseFiles($other, '1.0.0'), $headers + ['Accept' => 'application/json'])->assertNotFound();

    // routes outside the allowlist resolve nobody
    $this->getJson('/v1/account', $headers)->assertUnauthorized();
    $this->getJson('/v1/account/tokens', $headers)->assertUnauthorized();
    $this->postJson('/v1/publisher/extensions', ['name' => 'Sneaky', 'packages' => ['npm' => '@x/y']], $headers)->assertUnauthorized();
    $this->patchJson('/v1/publisher/extensions/'.$this->extension->slug, ['visibility' => 'public'], $headers)->assertUnauthorized();
    $this->getJson('/v1/publisher/extensions/'.$this->extension->slug.'/access', $headers)->assertUnauthorized();
    $this->postJson('/v1/publisher/extensions/'.$this->extension->slug.'/trusted-publishers', [], $headers)->assertUnauthorized();

    // and never downloads packages or sees private catalog entries
    $this->getJson('/v1/catalog/extensions/'.$this->extension->slug, $headers)->assertNotFound();

    // it does not show among the account's tokens
    actingAsAccount($this->account);
    expect(collect($this->getJson('/v1/account/tokens')->json('data'))->pluck('masked')->filter(fn ($m) => str_starts_with((string) $m, 'flbt_')))->toBeEmpty();
    expect($other)->toBeInstanceOf(Extension::class);
});

it('refuses tokens that do not verify', function (array $claims, ?string $kid, string $message) {
    if ($kid !== null) {
        oidcKeyPair($kid); // signed with a key GitHub never published
    }

    exchange(githubToken($claims, $kid ?? 'test-key'), $this->extension->slug)->assertUnauthorized()
        ->assertJsonPath('errors.0', fn (string $error) => str_contains($error, $message));
})->with([
    'bad audience' => [['aud' => 'registry.example.com'], null, 'audience must be localhost'],
    'audience list without ours' => [['aud' => ['sigstore', 'npm']], null, 'audience'],
    'expired' => [['exp' => time() - 120, 'iat' => time() - 600, 'nbf' => time() - 600], null, 'Expired'],
    'not yet valid' => [['nbf' => time() + 600], null, 'invalid'],
    'wrong issuer' => [['iss' => 'https://evil.example.com'], null, 'not issued by GitHub'],
    'unknown key' => [[], 'rogue-key', 'invalid'],
]);

it('refuses garbage and fetches the JWKS again for an unknown key id', function () {
    exchange('not-a-jwt', $this->extension->slug)->assertUnauthorized();

    // GitHub rotated keys: the cached set lacks the new kid, the refetch has it
    exchange(githubToken(), $this->extension->slug)->assertOk();
    fakeGithubJwks('test-key', 'rotated-key');
    exchange(githubToken(kid: 'rotated-key'), $this->extension->slug)->assertOk();
});

it('refuses tokens whose claims match no trusted publisher', function (array $claims) {
    exchange(githubToken($claims), $this->extension->slug)->assertForbidden()
        ->assertJsonPath('errors.0', fn (string $error) => str_contains($error, 'No trusted publisher'));
})->with([
    'wrong repository' => [['repository' => 'acme/other-repo', 'job_workflow_ref' => 'acme/other-repo/.github/workflows/release.yml@refs/tags/v1.2.0']],
    'wrong owner' => [['repository_owner' => 'mallory']],
    'wrong ref' => [['ref' => 'refs/heads/main', 'job_workflow_ref' => 'acme/secret-ext/.github/workflows/release.yml@refs/heads/main']],
    'pull request ref' => [['ref' => 'refs/pull/7/merge', 'job_workflow_ref' => 'acme/secret-ext/.github/workflows/release.yml@refs/pull/7/merge']],
    'wrong workflow' => [['job_workflow_ref' => 'acme/secret-ext/.github/workflows/ci.yml@refs/tags/v1.2.0']],
    'reusable workflow elsewhere' => [['job_workflow_ref' => 'mallory/tools/.github/workflows/release.yml@refs/tags/v1.2.0']],
    'workflow ref differs from ref' => [['job_workflow_ref' => 'acme/secret-ext/.github/workflows/release.yml@refs/heads/evil']],
]);

it('answers the same 403 for an unknown extension', function () {
    exchange(githubToken(), 'no-such-extension')->assertForbidden()
        ->assertJsonPath('errors.0', fn (string $error) => str_contains($error, 'No trusted publisher of no-such-extension'));
});

it('checks the environment when one is configured', function () {
    $this->publisher->forceFill(['environment' => 'release'])->save();

    exchange(githubToken(), $this->extension->slug)->assertForbidden();
    exchange(githubToken(['environment' => 'staging']), $this->extension->slug)->assertForbidden();
    exchange(githubToken(['environment' => 'Release']), $this->extension->slug)->assertOk();
});

it('refuses a re-created repository once the ids are pinned', function () {
    exchange(githubToken(), $this->extension->slug)->assertOk();

    exchange(githubToken(['repository_id' => '999999']), $this->extension->slug)->assertForbidden();
    exchange(githubToken(['repository_owner_id' => '1']), $this->extension->slug)->assertForbidden();
});

it('refuses when the configuring account can no longer publish', function () {
    $this->account->organizations()->updateExistingPivot($this->organization->uuid, ['role' => Membership::ROLE_MEMBER]);

    exchange(githubToken(), $this->extension->slug)->assertForbidden()
        ->assertJsonPath('errors.0', fn (string $error) => str_contains($error, 'can no longer publish'));
});

it('answers 503 when GitHub\'s keys cannot be fetched', function () {
    fakeGithubJwks(null);

    exchange(githubToken(), $this->extension->slug)->assertStatus(503);
});

it('manages trusted publishers for owners and admins', function () {
    actingAsAccount($this->account, [Token::SCOPE_PUBLISH]);
    $base = '/v1/publisher/extensions/'.$this->extension->slug.'/trusted-publishers';

    $this->getJson($base)->assertOk()->assertJsonPath('0.repository', 'acme/secret-ext');

    $created = $this->postJson($base, [
        'repository' => 'acme/another',
        'workflow' => 'publish.yaml',
        'environment' => 'release',
        'refs' => ['refs/tags/v*', 'refs/heads/main'],
    ])->assertCreated()->assertJsonPath('environment', 'release')->assertJsonPath('created_by.name', $this->account->name)->json('id');

    $this->postJson($base, ['repository' => 'not a repo', 'workflow' => '.github/workflows/x.yml', 'refs' => ['main']])
        ->assertUnprocessable()->assertJsonValidationErrors(['repository', 'workflow', 'refs.0'], 'fields');

    // deleting revokes the tokens it issued
    $this->extension->trustedPublishers()->where('public_id', $created)->update(['repository' => 'acme/another']);
    $plain = exchange(githubToken(['repository' => 'acme/another', 'environment' => 'release', 'job_workflow_ref' => 'acme/another/.github/workflows/publish.yaml@refs/tags/v1.2.0']), $this->extension->slug)
        ->assertOk()->json('token');
    actingAsAccount($this->account, [Token::SCOPE_PUBLISH]);
    $this->deleteJson("{$base}/{$created}")->assertNoContent();
    expect(Token::findActive($plain))->toBeNull()
        ->and(TrustedPublisher::query()->where('public_id', $created)->exists())->toBeFalse();

    $publisherRole = Account::factory()->create();
    $publisherRole->organizations()->attach($this->organization->uuid, ['role' => Membership::ROLE_PUBLISHER]);
    actingAsAccount($publisherRole, [Token::SCOPE_PUBLISH]);
    $this->getJson($base)->assertForbidden();
});
