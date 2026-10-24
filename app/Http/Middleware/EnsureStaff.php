<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `staff` — the account belongs to an organization flagged `is_staff`.
 */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $account = $request->user();

        if (! $account instanceof Account || ! $account->isStaff()) {
            return response()->error('Only Fleetbase staff can do this.', 403);
        }

        return $next($request);
    }
}
