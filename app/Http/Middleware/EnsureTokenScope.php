<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `scope:publish` — the authenticated token must carry the scope (or `*`).
 */
class EnsureTokenScope
{
    public function handle(Request $request, Closure $next, string ...$scopes): Response
    {
        $account = $request->user();

        if (! $account instanceof Account || ! $account->currentToken()) {
            return response()->error('Unauthenticated.', 401);
        }

        foreach ($scopes as $scope) {
            if (! $account->tokenCan($scope)) {
                return response()->error("This token does not have the '{$scope}' scope.", 403);
            }
        }

        return $next($request);
    }
}
