<?php

namespace App\Http\Controllers\Auth;

use App\Auth\SessionGuard;
use App\Http\Controllers\Controller;
use App\Http\Resources\AccountResource;
use App\Models\Account;
use App\Models\Event;
use App\Models\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The publisher website's cookie session (the `web` guard). These routes always run through
 * StatefulSession, so they have a session and unsafe methods need the `X-XSRF-TOKEN` header.
 * The session holds the uuid of a `flbs_` token row; its plaintext is never shown or kept.
 */
class SessionController extends Controller
{
    use ChecksCredentials;

    /**
     * GET /v1/auth/session — whether the session is signed in, and as whom. Always 200;
     * also sets the session and `XSRF-TOKEN` cookies the website needs before signing in.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Account|null $account */
        $account = Auth::guard('web')->user();

        return response()->json([
            'authenticated' => $account !== null,
            'account' => $account ? (new AccountResource($account->load('organizations')))->resolve($request) : null,
        ]);
    }

    /**
     * POST /v1/auth/session — email and password. Same checks and error codes as
     * `auth/login`; on success the session id is regenerated and bound to a new session token.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'string', 'email:rfc'],
            'password' => ['required', 'string'],
        ]);

        if (! $request->hasSession()) {
            return response()->error('Sign in with a cookie session, or use /v1/auth/login for a bearer token.', 400);
        }

        $account = $this->attempt($request);
        if ($account instanceof JsonResponse) {
            return $account;
        }

        // Signing in over an existing session replaces its token.
        SessionGuard::token($request)?->revoke();

        $token = Token::issue(
            $account,
            'web session '.now()->toDateString(),
            [Token::SCOPE_ALL],
            now()->addMinutes(SessionGuard::lifetimeMinutes()),
            null,
            Token::PREFIX_SESSION,
        );
        $token->plainTextToken = null;

        $request->session()->regenerate();
        $request->session()->put(SessionGuard::SESSION_KEY, $token->uuid);

        $account->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Event::record('account.login', $account, $account, ['token' => $token->public_id, 'via' => 'session'], $request->ip());

        return response()->json([
            'account' => (new AccountResource($account->load('organizations')))->resolve($request),
        ]);
    }

    /**
     * DELETE /v1/auth/session — revokes the session token, invalidates the session and
     * issues a new CSRF token. 200 even when the session was not signed in.
     */
    public function destroy(Request $request): JsonResponse
    {
        if ($request->hasSession()) {
            $token = SessionGuard::token($request);
            if ($token !== null) {
                $token->revoke();
                Event::record('account.logout', $token->account, $token->account, ['token' => $token->public_id, 'via' => 'session'], $request->ip());
            }

            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['authenticated' => false, 'message' => 'Signed out.']);
    }
}
