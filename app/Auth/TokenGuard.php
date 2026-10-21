<?php

namespace App\Auth;

use App\Models\Account;
use App\Models\Token;
use Illuminate\Http\Request;

/**
 * Resolves the account behind an `Authorization: Bearer flbp_…` header.
 *
 * Registered as the `registry-token` guard driver through `Auth::viaRequest`.
 * The token is SHA-256 hashed and matched against `tokens.token_hash`; expired,
 * revoked, or foreign-prefix tokens resolve to nobody. Each successful use stamps
 * `last_used_at` and `last_used_ip` on the token row.
 */
class TokenGuard
{
    public function __invoke(Request $request): ?Account
    {
        $plain = (string) $request->bearerToken();

        if ($plain === '' || ! str_starts_with($plain, Token::PREFIX_PERSONAL)) {
            return null;
        }

        $token = Token::findActive($plain);
        if (! $token) {
            return null;
        }

        $account = $token->account;
        if (! $account || ! $account->isActive()) {
            return null;
        }

        $token->touchUsage($request->ip());
        $request->attributes->set('token', $token);

        return $account->withCurrentToken($token);
    }
}
