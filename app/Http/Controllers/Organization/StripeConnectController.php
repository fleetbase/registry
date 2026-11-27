<?php

namespace App\Http\Controllers\Organization;

use App\Commerce\ConnectAccounts;
use App\Commerce\Stripe;
use App\Http\Controllers\Controller;
use App\Models\Membership;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * An organization's Stripe Connect Express account, which receives the money from its paid
 * extensions. Members see its status; owners and admins create it and open Account Sessions for
 * the embedded onboarding and management components on the website.
 */
class StripeConnectController extends Controller
{
    use ActsForOrganization;

    public const MANAGING_ROLES = [Membership::ROLE_OWNER, Membership::ROLE_ADMIN];

    /**
     * GET /v1/organizations/{slug}/stripe[?refresh=1] — the connected account's status. Owners and
     * admins may refresh it from Stripe (the website does when onboarding exits).
     */
    public function show(Request $request, ConnectAccounts $accounts, string $slug): JsonResponse
    {
        $organization = $this->findOrganization($request, $slug);

        if ($request->boolean('refresh') && $organization->hasStripeAccount() && in_array($organization->pivot?->role, self::MANAGING_ROLES, true)) {
            $accounts->refresh($organization);
        }

        return response()->json(ConnectAccounts::summary($organization));
    }

    /**
     * POST /v1/organizations/{slug}/stripe/account — create the Express account (once), in the
     * organization's `country` (ISO 3166-1 alpha-2), or `REGISTRY_CONNECT_COUNTRY` when that is set.
     */
    public function store(Request $request, ConnectAccounts $accounts, string $slug): JsonResponse
    {
        $organization = $this->findOrganization($request, $slug);
        $this->assertRole($organization, self::MANAGING_ROLES, 'Only organization owners and admins can set up payouts.');
        $created = ! $organization->hasStripeAccount();

        // The account's country decides its onboarding (identity checks, bank, tax forms) and payout
        // currency, and Stripe cannot change it later. Without one Stripe uses the platform's country,
        // so every publisher would be onboarded as a business there.
        $country = null;
        if ($created) {
            $fixed = config('registry.commerce.connect_country');
            $validated = $request->validate([
                'country' => [filled($fixed) ? 'nullable' : 'required', 'string', 'size:2', 'alpha'],
            ], [
                'country.required' => 'Choose the country your organization is based in.',
            ]);
            $country = filled($fixed) ? strtoupper((string) $fixed) : strtoupper((string) $validated['country']);
        }

        $accounts->create($organization, $this->account($request), $country);

        return response()->json(ConnectAccounts::summary($organization), $created ? 201 : 200);
    }

    /**
     * POST /v1/organizations/{slug}/stripe/account-session — a client secret for Connect.js
     * (onboarding, account management, payouts, tax settings and the notification banner).
     */
    public function session(Request $request, ConnectAccounts $accounts, string $slug): JsonResponse
    {
        $organization = $this->findOrganization($request, $slug);
        $this->assertRole($organization, self::MANAGING_ROLES, 'Only organization owners and admins can set up payouts.');

        if (! $organization->hasStripeAccount()) {
            throw new UnprocessableEntityHttpException('Create the Stripe account first.');
        }

        $session = $accounts->session($organization);

        return response()->json([
            'client_secret' => $session->client_secret,
            'expires_at' => $session->expires_at,
            'publishable_key' => config('services.stripe.key'),
            'components' => ConnectAccounts::COMPONENTS,
        ]);
    }
}
