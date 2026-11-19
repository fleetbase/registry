<?php

namespace App\Http\Middleware;

use App\Support\Maintenance;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `maintenance` — answers 503 with the maintenance message while the cutover freeze is on (see
 * `Maintenance`). On publishing and checkout routes; reads are never frozen. `maintenance:write`
 * lets GET and HEAD through on a group that mixes reads and writes.
 */
class RefuseDuringMaintenance
{
    public function handle(Request $request, Closure $next, ?string $only = null): Response
    {
        if ($only === 'write' && in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if (! Maintenance::active()) {
            return $next($request);
        }

        return response()
            ->error(Maintenance::message(), 503, ['maintenance' => true])
            ->header('Retry-After', (string) max(60, (int) config('registry.maintenance.retry_after', 3600)));
    }
}
