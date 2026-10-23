<?php

namespace App\Auth;

use App\Models\Token;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * The `web` guard (`registry-session` driver): the publisher website's cookie session.
 *
 * Signing in stores the uuid of a `flbs_` token row under `registry.session_token` in the
 * session. The row carries the scopes (`*`), expiry and revocation like any other token, so
 * `scope:*` and `staff` work unchanged; its plaintext is never returned and is discarded at
 * creation, so a session token can only be used through the session. Expiry slides: when
 * less than half of SESSION_LIFETIME remains, a request pushes it a full lifetime forward.
 */
class SessionGuard
{
    public const SESSION_KEY = 'registry.session_token';

    public function __invoke(Request $request): ?Authenticatable
    {
        $token = static::token($request);
        if ($token === null) {
            return null;
        }

        $account = $token->account;
        if (! $account || ! $account->isActive()) {
            return null;
        }

        $this->touch($token, $request);
        $request->attributes->set('token', $token);

        return $account->withCurrentToken($token);
    }

    /**
     * The active session token behind the request's session, or null.
     */
    public static function token(Request $request): ?Token
    {
        if (! $request->hasSession()) {
            return null;
        }

        $uuid = $request->session()->get(self::SESSION_KEY);
        if (! is_string($uuid) || $uuid === '') {
            return null;
        }

        return Token::query()->active()->where('uuid', $uuid)->where('prefix', Token::PREFIX_SESSION)->first();
    }

    public static function lifetimeMinutes(): int
    {
        return max(1, (int) config('session.lifetime', 120));
    }

    /**
     * Stamp last-used data and slide the expiry when less than half the lifetime remains.
     */
    protected function touch(Token $token, Request $request): void
    {
        $lifetime = static::lifetimeMinutes();
        $attributes = ['last_used_at' => now(), 'last_used_ip' => $request->ip()];

        if ($token->expires_at !== null && now()->diffInSeconds($token->expires_at) < $lifetime * 30) {
            $attributes['expires_at'] = now()->addMinutes($lifetime);
        }

        $token->forceFill($attributes)->saveQuietly();
    }
}
