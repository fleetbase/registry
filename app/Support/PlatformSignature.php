<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Requests signed with the cloud platform secret (`registry.cloud.platform_secret`), which the
 * cloud console's API shares with the service. Three headers:
 *
 *   X-Registry-Timestamp  unix seconds, within `registry.cloud.signature_tolerance` of now
 *   X-Registry-Nonce      16 to 64 characters, never reused within that window
 *   X-Registry-Signature  `v1=` + hex HMAC-SHA256 of "{timestamp}.{nonce}.{raw body}"
 *
 * The bridge builds the same headers (`CloudRegistration::signatureHeaders` in registry-bridge).
 */
class PlatformSignature
{
    public const TIMESTAMP_HEADER = 'X-Registry-Timestamp';

    public const NONCE_HEADER = 'X-Registry-Nonce';

    public const SIGNATURE_HEADER = 'X-Registry-Signature';

    public static function enabled(): bool
    {
        return static::secret() !== null;
    }

    /**
     * Why the request is not validly signed, or null when it is. A valid signature uses up its nonce.
     */
    public static function refusal(Request $request): ?string
    {
        $secret = static::secret();
        if ($secret === null) {
            return 'Cloud registration is not enabled on this registry.';
        }

        $timestamp = (string) $request->header(self::TIMESTAMP_HEADER, '');
        $nonce = (string) $request->header(self::NONCE_HEADER, '');
        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');

        if (! ctype_digit($timestamp) || ! preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) || ! str_starts_with($signature, 'v1=')) {
            return 'The request is not signed with the platform secret.';
        }

        $tolerance = max(30, (int) config('registry.cloud.signature_tolerance', 300));
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return 'The signed request has expired. Check the clock on the calling server.';
        }

        $expected = static::sign($secret, $timestamp, $nonce, $request->getContent());
        if (! hash_equals($expected, substr($signature, 3))) {
            return 'The request is not signed with the platform secret.';
        }

        // A replayed request would rotate the instance token again and hand the caller the new one.
        if (! Cache::add('registry:platform-nonce:'.hash('sha256', $nonce), true, $tolerance * 2)) {
            return 'This signed request was already used.';
        }

        return null;
    }

    public static function sign(string $secret, string $timestamp, string $nonce, string $body): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$nonce.'.'.$body, $secret);
    }

    /**
     * Headers for a body signed now (tests and tooling).
     *
     * @return array<string, string>
     */
    public static function headers(string $secret, string $body, ?int $timestamp = null, ?string $nonce = null): array
    {
        $timestamp = (string) ($timestamp ?? time());
        $nonce ??= bin2hex(random_bytes(16));

        return [
            self::TIMESTAMP_HEADER => $timestamp,
            self::NONCE_HEADER => $nonce,
            self::SIGNATURE_HEADER => 'v1='.static::sign($secret, $timestamp, $nonce, $body),
        ];
    }

    protected static function secret(): ?string
    {
        $secret = config('registry.cloud.platform_secret');

        return is_string($secret) && strlen(trim($secret)) > 0 ? $secret : null;
    }
}
