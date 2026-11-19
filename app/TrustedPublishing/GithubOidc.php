<?php

namespace App\TrustedPublishing;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Verifies a GitHub Actions OIDC token (a workflow with `permissions: id-token: write` asks for
 * it with the registry's audience): RS256 signature against GitHub's JWKS, issuer, audience,
 * expiry and not-before (60 s leeway). Returns the claims; `TokenExchange` matches them against
 * the extension's trusted publishers.
 *
 * The JWKS is cached for an hour and fetched again once when a token names a key id the
 * cached set does not have (GitHub rotates keys).
 */
class GithubOidc
{
    public const ISSUER = 'https://token.actions.githubusercontent.com';

    public const LEEWAY_SECONDS = 60;

    protected const JWKS_CACHE_KEY = 'trusted-publishing:github:jwks';

    /**
     * @return array<string, mixed> the verified claims
     *
     * @throws OidcTokenInvalid
     */
    public function verify(string $jwt): array
    {
        $kid = $this->keyId($jwt);

        $jwks = $this->jwks();
        if ($kid !== null && ! $this->hasKey($jwks, $kid)) {
            $jwks = $this->jwks(refresh: true);
        }

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = self::LEEWAY_SECONDS;
        try {
            $claims = (array) JWT::decode($jwt, JWK::parseKeySet($jwks, 'RS256'));
        } catch (\Throwable $e) {
            throw new OidcTokenInvalid('The OIDC token is invalid: '.$e->getMessage(), 0, $e);
        } finally {
            JWT::$leeway = $previousLeeway;
        }

        if (($claims['iss'] ?? null) !== self::ISSUER) {
            throw new OidcTokenInvalid('The OIDC token was not issued by GitHub Actions.');
        }

        $audience = static::audience();
        $aud = (array) ($claims['aud'] ?? []);
        if (! in_array($audience, $aud, true)) {
            throw new OidcTokenInvalid("The OIDC token's audience must be {$audience}.");
        }

        if (! isset($claims['exp'])) {
            throw new OidcTokenInvalid('The OIDC token has no expiry.');
        }

        return $claims;
    }

    /**
     * The audience tokens must be minted for: `registry.trusted_publishing.audience`, by default
     * the registry's host name, so a token for one registry (staging) never works on another.
     */
    public static function audience(): string
    {
        $configured = config('registry.trusted_publishing.audience');
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return (string) (parse_url((string) (config('registry.public_url') ?: config('app.url')), PHP_URL_HOST) ?: 'localhost');
    }

    protected function keyId(string $jwt): ?string
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new OidcTokenInvalid('The OIDC token is not a JWT.');
        }

        try {
            $header = json_decode(JWT::urlsafeB64Decode($parts[0]), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new OidcTokenInvalid('The OIDC token is not a JWT.');
        }

        return is_array($header) && is_string($header['kid'] ?? null) ? $header['kid'] : null;
    }

    protected function jwks(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::JWKS_CACHE_KEY);
        }

        return Cache::remember(self::JWKS_CACHE_KEY, 3600, function () {
            $response = Http::acceptJson()->timeout(10)->get((string) config('registry.trusted_publishing.github_jwks_url'));
            $keys = $response->successful() ? $response->json() : null;

            if (! is_array($keys) || ! is_array($keys['keys'] ?? null)) {
                throw new \RuntimeException('Could not fetch the GitHub Actions OIDC keys.');
            }

            return $keys;
        });
    }

    protected function hasKey(array $jwks, string $kid): bool
    {
        foreach ($jwks['keys'] ?? [] as $key) {
            if (($key['kid'] ?? null) === $kid) {
                return true;
            }
        }

        return false;
    }
}
