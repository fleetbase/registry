<?php

namespace App\Auth;

use App\Models\Account;
use App\Models\Instance;
use App\Models\Token;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Resolves the caller behind an `Authorization: Bearer …` header.
 *
 * One guard driver per token kind, registered through `Auth::viaRequest`:
 * `registry-token` accepts personal tokens (`flbp_`) and resolves an Account;
 * `registry-instance-token` accepts instance tokens (`flbi_`) and resolves an Instance.
 * The prefix is the kind check: a token of another kind resolves to nobody, so an
 * instance token never reaches account routes and vice versa.
 *
 * Tokens are SHA-256 hashed and matched against the stored hash; expired, revoked or
 * foreign-prefix tokens resolve to nobody. Each successful use stamps last-used data.
 */
class TokenGuard
{
    public const KIND_PERSONAL = 'personal';

    public const KIND_INSTANCE = 'instance';

    public function __construct(protected string $kind = self::KIND_PERSONAL) {}

    public function __invoke(Request $request): ?Authenticatable
    {
        $plain = (string) $request->bearerToken();

        return match ($this->kind) {
            self::KIND_INSTANCE => $this->resolveInstance($plain, $request),
            default => $this->resolveAccount($plain, $request),
        };
    }

    protected function resolveAccount(string $plain, Request $request): ?Account
    {
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

    protected function resolveInstance(string $plain, Request $request): ?Instance
    {
        if ($plain === '' || ! str_starts_with($plain, Token::PREFIX_INSTANCE)) {
            return null;
        }

        $instance = Instance::findByToken($plain);
        if (! $instance) {
            return null;
        }

        $instance->touchLastSeen($request->ip());

        return $instance;
    }
}
