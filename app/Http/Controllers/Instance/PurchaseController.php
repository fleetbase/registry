<?php

namespace App\Http\Controllers\Instance;

use App\Commerce\CheckoutSessions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instance\StartCheckoutRequest;
use App\Http\Resources\CheckoutResource;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Buying a paid extension from a console. The console starts a checkout, sends the operator to
 * Stripe's hosted page, and on return polls the hand-off until the webhook has created the
 * purchase and licence (see README, "Instance checkout").
 */
class PurchaseController extends Controller
{
    /**
     * POST /v1/instances/me/purchases/checkout — a hosted Checkout Session for the instance's
     * organization. The return and cancel URLs must be on the instance's registered host or its
     * domain. Answers 201 with the hand-off id and Stripe's URL.
     */
    public function checkout(StartCheckoutRequest $request, CheckoutSessions $sessions): JsonResponse
    {
        /** @var Instance $instance */
        $instance = $request->user();

        $extension = Extension::findByIdentifier($request->input('extension'));
        if (! $extension) {
            return response()->error('Extension not found.', 404);
        }

        foreach (array_filter([$request->input('return_url'), $request->input('cancel_url')]) as $url) {
            if (! $instance->ownsUrl($url)) {
                return response()->error($instance->hostname()
                    ? "Return URLs must be on this instance's host ({$instance->hostname()}) or its domain."
                    : 'This instance has no registered host. Register it again with its URL to buy extensions.', 422);
            }
        }

        $checkout = $sessions->start(
            $extension,
            $instance->organization,
            Checkout::SOURCE_INSTANCE,
            $request->input('return_url'),
            $request->input('cancel_url') ?? $request->input('return_url'),
            ['instance' => $instance, 'email' => $request->input('email')],
        );

        Event::record('checkout.started', $checkout, $instance, ['extension' => $extension->public_id, 'source' => Checkout::SOURCE_INSTANCE], $request->ip());

        return (new CheckoutResource($checkout->load(['extension', 'organization'])))->response()->setStatusCode(201);
    }

    /**
     * GET /v1/instances/me/purchases/{handoff} — the hand-off's status; `complete` carries the
     * purchase and its licence. Any checkout of the instance's organization.
     */
    public function show(Request $request, string $handoff): CheckoutResource
    {
        /** @var Instance $instance */
        $instance = $request->user();

        $checkout = Checkout::query()
            ->where('public_id', $handoff)
            ->where('organization_uuid', $instance->organization_uuid)
            ->with(['extension', 'organization', 'purchase.extension', 'purchase.licence'])
            ->first();

        if (! $checkout) {
            throw new NotFoundHttpException('Checkout not found.');
        }

        return new CheckoutResource($checkout);
    }
}
