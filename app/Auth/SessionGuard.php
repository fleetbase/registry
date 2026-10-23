<?php

namespace App\Auth;

use App\Models\Account;
use App\Models\Event;
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

    /**
     * Sign the account in on the request's session: revoke the session's previous token,
     * issue a `flbs_` token (plaintext discarded), regenerate the session id and store the
     * token's uuid. Used by POST /v1/auth/session and Sign in with GitHub.
     */
    public static function signIn(Request $request, Account $account, string $via = 'session'): Token
    {
        // Signing in over an existing session replaces its token.
        static::token($request)?->revoke();

        $token = Token::issue(
            $account,
            'web session '.now()->toDateString(),
            [Token::SCOPE_ALL],
            now()->addMinutes(static::lifetimeMinutes()),
            null,
            Token::PREFIX_SESSION,
        );
        $token->plainTextToken = null;

        $request->session()->regenerate();
        $request->session()->put(self::SESSION_KEY, $token->uuid);

        $account->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Event::record('account.login', $account, $account, ['token' => $token->public_id, 'via' => $via], $request->ip());

        return $token;
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
