<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Organization\OrganizationController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign in, create account, verify; then profile, organizations and tokens for the signed-in
 * account. Sign-in itself is POST /v1/auth/session from the page.
 */
class AccountPageController extends WebController
{
    /**
     * GET /sign-in (named `login`, where `auth:web` sends visitors).
     */
    public function signIn(Request $request): Response|RedirectResponse
    {
        if (Auth::guard('web')->check()) {
            return redirect()->route('studio');
        }

        return Inertia::render('Auth/SignIn', [
            'email' => $request->query('email'),
            'intended' => $request->session()->get('url.intended'),
            // Set by the verify page, which sends a just-verified account here.
            'verified' => $request->boolean('verified'),
        ]);
    }

    public function register(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function verify(Request $request): Response
    {
        return Inertia::render('Auth/Verify', [
            'email' => $request->query('email'),
            'sent' => $request->boolean('sent'),
        ]);
    }

    public function profile(): Response
    {
        return Inertia::render('Account/Profile');
    }

    /**
     * GET /account/organizations[/{slug}] — the list, plus one organization's profile and
     * members (the selected one, else the first that is not personal, else the first).
     */
    public function organizations(Request $request, ?string $slug = null): Response
    {
        $organizations = $this->api($request, '/v1/organizations', [], OrganizationController::class, 'index');

        $selected = $slug ?? (collect($organizations)->firstWhere('is_personal', false)['slug'] ?? ($organizations[0]['slug'] ?? null));

        return Inertia::render('Account/Organizations', [
            'organizations' => $organizations,
            'organization' => $selected
                ? $this->api($request, "/v1/organizations/{$selected}", [], OrganizationController::class, 'show', ['slug' => $selected])
                : null,
        ]);
    }

    public function tokens(Request $request): Response
    {
        return Inertia::render('Account/Tokens', [
            'tokens' => $this->api($request, '/v1/account/tokens', [], TokenController::class, 'index'),
        ]);
    }
}
