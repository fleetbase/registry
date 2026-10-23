<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Routing\Pipeline;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cookie sessions for the publisher website (the `web` guard), after Sanctum's
 * `EnsureFrontendRequestsAreStateful`.
 *
 * A request runs through EncryptCookies, AddQueuedCookiesToResponse, StartSession and
 * VerifyCsrfToken when it has no `Authorization: Bearer` header and either targets a
 * `/v1/auth/session` route or carries the session or XSRF cookie. Bearer requests (the CLI,
 * instances, package clients) skip all of it: no session, no CSRF, no cookies. A request
 * with neither a bearer token nor a cookie is unauthenticated either way, so leaving it
 * stateless lets the CLI keep calling `auth/login` and `auth/register` without a CSRF token.
 */
class StatefulSession
{
    /** Routes that always start a session (sign-in needs one before any cookie exists). */
    public const SESSION_ROUTES = 'v1.auth.session.*';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->isStateful($request)) {
            return $next($request);
        }

        return (new Pipeline(app()))
            ->send($request)
            ->through([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, VerifyCsrfToken::class])
            ->then(fn (Request $request) => $next($request));
    }

    public static function isStateful(Request $request): bool
    {
        if (Str::startsWith((string) $request->header('Authorization'), ['Bearer ', 'bearer '])) {
            return false;
        }

        return $request->routeIs(self::SESSION_ROUTES)
            || $request->cookies->has((string) config('session.cookie'))
            || $request->cookies->has('XSRF-TOKEN');
    }
}
