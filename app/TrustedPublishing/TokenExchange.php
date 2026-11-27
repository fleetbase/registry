<?php

namespace App\TrustedPublishing;

use App\Models\Account;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Membership;
use App\Models\Token;
use App\Models\TrustedPublisher;
use Illuminate\Support\Str;

/**
 * Exchanges verified GitHub Actions OIDC claims for a short-lived publish token.
 *
 * The claims must match one of the extension's trusted publishers:
 *   - `repository` is the publisher's `owner/repo` and `repository_owner` its owner (case
 *     insensitive, as GitHub names are);
 *   - `job_workflow_ref` is `{repository}/.github/workflows/{workflow}@{ref}`: the job runs that
 *     workflow file of that repository (a reusable workflow in another repository never matches);
 *   - `ref` matches one of the publisher's ref patterns;
 *   - `environment` is the publisher's environment, when it has one;
 *   - `repository_id` and `repository_owner_id` match the ids pinned on first use, so a deleted
 *     and re-created repository or account cannot take the configuration over.
 *
 * The token (`flbt_`, scope `publish`) acts as the account that configured the publisher, is
 * pinned to the extension's organization and limited to the extension: `TokenGuard` only lets
 * it reach the release routes, and the publisher controllers only that extension.
 */
class TokenExchange
{
    public const NO_MATCH = 'No trusted publisher of %s accepts this token. Check the repository, workflow file, environment and ref configured in Studio → Access.';

    /**
     * @param  array<string, mixed>  $claims  from GithubOidc::verify
     * @return array{token: Token, publisher: TrustedPublisher, extension: Extension}
     *
     * @throws NoMatchingPublisher
     */
    public function exchange(array $claims, string $identifier): array
    {
        $noMatch = new NoMatchingPublisher(sprintf(self::NO_MATCH, $identifier));

        $extension = Extension::findByIdentifier($identifier);
        if ($extension === null || $extension->status === Extension::STATUS_SUSPENDED) {
            throw $noMatch;
        }

        $publisher = $extension->trustedPublishers()
            ->where('provider', TrustedPublisher::PROVIDER_GITHUB)
            ->where('repository', strtolower((string) ($claims['repository'] ?? '')))
            ->orderBy('id')
            ->get()
            ->first(fn (TrustedPublisher $publisher) => $this->matches($publisher, $claims));

        if ($publisher === null) {
            throw $noMatch;
        }

        $account = $publisher->createdBy;
        if (! $account instanceof Account || ! $account->isActive()
            || ! in_array($account->roleIn($extension->organization_uuid), Membership::PUBLISHING_ROLES, true)) {
            throw new NoMatchingPublisher("The account that set up this trusted publisher can no longer publish {$extension->slug}; an owner or admin must set it up again.");
        }

        $publisher->forceFill(array_filter([
            'repository_id' => $publisher->repository_id ?? self::stringClaim($claims, 'repository_id'),
            'repository_owner_id' => $publisher->repository_owner_id ?? self::stringClaim($claims, 'repository_owner_id'),
            'last_used_at' => now(),
        ], fn ($value) => $value !== null))->save();

        $ref = (string) $claims['ref'];
        $token = Token::issue(
            $account,
            Str::limit("GitHub Actions: {$publisher->repository} {$ref}", 250, ''),
            [Token::SCOPE_PUBLISH],
            now()->addMinutes(max(1, (int) config('registry.trusted_publishing.token_minutes', 15))),
            $extension->organization_uuid,
            Token::PREFIX_TRUSTED,
            ['extension_uuid' => $extension->uuid, 'trusted_publisher_uuid' => $publisher->uuid],
        );

        Event::record('trusted_publisher.exchanged', $publisher, $account, array_filter([
            'extension' => $extension->public_id,
            'token' => $token->public_id,
            'repository' => $claims['repository'] ?? null,
            'ref' => $ref,
            'sha' => $claims['sha'] ?? null,
            'workflow_ref' => $claims['job_workflow_ref'] ?? null,
            'run_id' => $claims['run_id'] ?? null,
            'actor' => $claims['actor'] ?? null,
            'environment' => $claims['environment'] ?? null,
        ]));

        return ['token' => $token, 'publisher' => $publisher, 'extension' => $extension];
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function matches(TrustedPublisher $publisher, array $claims): bool
    {
        $repository = strtolower((string) ($claims['repository'] ?? ''));
        if ($repository === '' || $repository !== $publisher->repository) {
            return false;
        }

        if (strtolower((string) ($claims['repository_owner'] ?? '')) !== $publisher->owner()) {
            return false;
        }

        $ref = $claims['ref'] ?? null;
        if (! is_string($ref) || $ref === '' || ! $publisher->allowsRef($ref)) {
            return false;
        }

        // `owner/repo/.github/workflows/release.yml@refs/tags/v1.0.0`
        $workflowRef = (string) ($claims['job_workflow_ref'] ?? '');
        $expected = $publisher->repository.'/.github/workflows/'.$publisher->workflow.'@';
        if (! str_starts_with(strtolower($workflowRef), strtolower($expected)) || substr($workflowRef, strlen($expected)) !== $ref) {
            return false;
        }

        if ($publisher->environment !== null && strcasecmp((string) ($claims['environment'] ?? ''), $publisher->environment) !== 0) {
            return false;
        }

        foreach (['repository_id', 'repository_owner_id'] as $pinned) {
            if ($publisher->{$pinned} !== null && self::stringClaim($claims, $pinned) !== $publisher->{$pinned}) {
                return false;
            }
        }

        return true;
    }

    protected static function stringClaim(array $claims, string $key): ?string
    {
        $value = $claims[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }
}
