<?php

namespace App\Http\Controllers\Webhook;

use App\Commerce\StripeWebhooks;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;

/**
 * POST /v1/webhooks/stripe — the platform endpoint and the Connect endpoint both post here; each
 * signs with its own secret (`STRIPE_WEBHOOK_SECRET`, comma-separated). Answers 400 for a bad
 * signature, 200 once handled (or a duplicate), and 500 when handling failed so Stripe retries.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeWebhooks $webhooks): JsonResponse
    {
        $secrets = (array) config('services.stripe.webhook_secrets', []);
        if ($secrets === []) {
            return response()->error('Stripe webhooks are not set up on this registry.', 503);
        }

        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature');
        $event = null;

        foreach ($secrets as $secret) {
            try {
                $event = Webhook::constructEvent($payload, $signature, $secret, (int) config('services.stripe.webhook_tolerance', 300));
                break;
            } catch (SignatureVerificationException) {
                continue;
            } catch (\UnexpectedValueException) {
                return response()->error('The payload is not a Stripe event.', 400);
            }
        }

        if ($event === null) {
            return response()->error('The Stripe signature is invalid.', 400);
        }

        try {
            $status = $webhooks->handle($event);
        } catch (\Throwable $e) {
            Log::error('Stripe webhook failed', ['event' => $event->id, 'type' => $event->type, 'error' => $e->getMessage()]);
            report($e);

            return response()->error('The event could not be handled; Stripe will retry it.', 500);
        }

        return response()->json(['received' => true, 'event' => $event->id, 'status' => $status]);
    }
}
