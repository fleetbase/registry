<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Organization\BillingController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Purchase\CheckoutController;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Sign in, create account, verify, forgot password; then profile, organizations and tokens for the signed-in
 * account, and its organizations' billing. Sign-in itself is POST /v1/auth/session from the page.
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
            // Set by the forgot-password page after a reset.
            'reset' => $request->boolean('reset'),
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

    /**
     * GET /forgot-password (named `password.request`): the email, then the emailed code and
     * a new password. The reset email links here with `email` and `sent`.
     */
    public function forgotPassword(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', [
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

    /**
     * GET /account/billing[/{slug}][?handoff=] — what the organization bought, its licences with
     * their instances, and receipts. Stripe returns website buyers here with the hand-off, which
     * the page polls until the purchase lands.
     */
    public function billing(Request $request, ?string $slug = null): Response
    {
        [$organizations, $organization] = $this->billingOrganizations($request, $slug, null);
        $billing = $organization && in_array($organization['role'] ?? null, OrganizationController::BILLING_ROLES, true);
        $checkout = null;

        if ($organization && $request->filled('handoff')) {
            [$checkout] = $this->tryApi($request, '/v1/purchases/'.$request->query('handoff'), [], CheckoutController::class, 'show', ['handoff' => (string) $request->query('handoff')]);
        }

        return Inertia::render('Account/Billing', [
            'organizations' => $organizations,
            'organization' => $organization,
            // Purchases and receipts are for owners, admins and billing members; licences for everyone.
            'purchases' => $billing ? $this->api($request, "/v1/organizations/{$organization['slug']}/billing/purchases", [], BillingController::class, 'purchases', ['slug' => $organization['slug']]) : null,
            'licences' => $organization ? $this->api($request, "/v1/organizations/{$organization['slug']}/billing/licences", [], BillingController::class, 'licences', ['slug' => $organization['slug']]) : [],
            'checkout' => $checkout,
        ]);
    }

    public function tokens(Request $request): Response
    {
        return Inertia::render('Account/Tokens', [
            'tokens' => $this->api($request, '/v1/account/tokens', [], TokenController::class, 'index'),
        ]);
    }
}
