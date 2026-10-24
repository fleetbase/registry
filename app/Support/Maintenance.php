<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;

/**
 * The cutover freeze: while it is on, publishing and checkout answer 503 with a message, and
 * everything else (catalog, package downloads, installs, Stripe webhooks for checkouts already
 * paid) keeps working.
 *
 * `registry:maintenance on|off` stores the switch in the default cache store, which every app
 * and queue task shares (the database store in the deployed stack), so it applies without a
 * deploy. `REGISTRY_MAINTENANCE=true` forces it on regardless.
 */
class Maintenance
{
    public const CACHE_KEY = 'registry:maintenance';

    public static function active(): bool
    {
        return (bool) config('registry.maintenance.enabled') || static::stored() !== null;
    }

    public static function message(): string
    {
        $stored = static::stored();

        return trim((string) ($stored['message'] ?? '')) !== ''
            ? (string) $stored['message']
            : (string) config('registry.maintenance.message');
    }

    /**
     * @return array{active: bool, forced: bool, message: string, since: ?string}
     */
    public static function status(): array
    {
        $stored = static::stored();

        return [
            'active' => static::active(),
            'forced' => (bool) config('registry.maintenance.enabled'),
            'message' => static::message(),
            'since' => $stored['since'] ?? null,
        ];
    }

    public static function enable(?string $message = null): void
    {
        Cache::forever(self::CACHE_KEY, ['message' => $message, 'since' => now()->toIso8601String()]);
    }

    public static function disable(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * @return array{message: ?string, since: ?string}|null
     */
    protected static function stored(): ?array
    {
        try {
            $value = Cache::get(self::CACHE_KEY);
        } catch (\Throwable) {
            return null;
        }

        return is_array($value) ? $value : null;
    }
}
