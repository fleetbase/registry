<?php

namespace App\Http\Controllers\Purchase;

use App\Commerce\CheckoutSessions;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Organization\ActsForOrganization;
use App\Http\Requests\Purchase\StartCheckoutRequest;
use App\Http\Resources\CheckoutResource;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Extension;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Buying a paid extension on the website for one of the signed-in account's organizations. Same
 * Checkout Session as from a console; Stripe returns the buyer to the organization's billing page,
 * which polls the hand-off.
 */
class CheckoutController extends Controller
{
    use ActsForOrganization;

    /**
     * POST /v1/purchases/checkout — owners, admins and billing members of the buyer organization.
     */
    public function checkout(StartCheckoutRequest $request, CheckoutSessions $sessions): JsonResponse
    {
        $account = $this->account($request);
        $identifier = $request->input('organization') ?? $account->currentToken()?->organization_uuid ?? $account->personalOrganization()?->slug;
        if ($identifier === null) {
            throw new UnprocessableEntityHttpException('Choose the organization that is buying.');
        }

        $organization = $this->findOrganization($request, $identifier);
        $this->assertRole($organization, self::BILLING_ROLES, 'Only organization owners, admins and billing members can buy extensions.');

        $extension = Extension::findByIdentifier($request->input('extension'));
        if (! $extension) {
            return response()->error('Extension not found.', 404);
        }

        $site = rtrim((string) (config('registry.public_url') ?: $request->getSchemeAndHttpHost()), '/');
        $checkout = $sessions->start(
            $extension,
            $organization,
            Checkout::SOURCE_WEBSITE,
            "{$site}/account/billing/{$organization->slug}",
            "{$site}/extensions/{$extension->slug}",
            ['account' => $account, 'email' => $account->email],
        );

        Event::record('checkout.started', $checkout, $account, ['extension' => $extension->public_id, 'source' => Checkout::SOURCE_WEBSITE], $request->ip());

        return (new CheckoutResource($checkout->load(['extension', 'organization'])))->response()->setStatusCode(201);
    }

    /**
     * GET /v1/purchases/{handoff} — a checkout of one of the caller's organizations.
     */
    public function show(Request $request, string $handoff): CheckoutResource
    {
        $checkout = Checkout::query()
            ->where('public_id', $handoff)
            ->with(['extension', 'organization', 'purchase.extension', 'purchase.licence'])
            ->first();

        if (! $checkout || ! $checkout->organization) {
            throw new NotFoundHttpException('Checkout not found.');
        }
        $this->findOrganization($request, $checkout->organization->uuid);

        return new CheckoutResource($checkout);
    }
}
