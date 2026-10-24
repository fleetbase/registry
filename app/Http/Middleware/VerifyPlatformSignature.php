<?php

namespace App\Http\Middleware;

use App\Support\PlatformSignature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `platform` — the request is signed with the cloud platform secret (see `PlatformSignature`).
 * 403 when this registry has no platform secret, 401 for a missing, wrong, expired or replayed
 * signature.
 */
class VerifyPlatformSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($refusal = PlatformSignature::refusal($request)) {
            return response()->error($refusal, PlatformSignature::enabled() ? 401 : 403);
        }

        return $next($request);
    }
}
