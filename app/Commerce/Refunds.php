<?php

namespace App\Commerce;

use App\Models\Account;
use App\Models\Event;
use App\Models\Purchase;
use Illuminate\Validation\ValidationException;
use Stripe\Refund;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Staff refunds of a purchase. The refund reverses the publisher's transfer and returns
 * Fleetbase's application fee in proportion, so both sides give back their share. The purchase and
 * licence change when Stripe sends `charge.refunded` (see StripeWebhooks::refunded), not here.
 */
class Refunds
{
    /** Reasons Stripe accepts on a refund. */
    public const REASONS = ['requested_by_customer', 'duplicate', 'fraudulent'];

    /**
     * What is left to refund, in minor units: nothing once fully refunded, while a dispute is
     * open (Stripe refuses refunds of a disputed charge) or without a Stripe payment.
     */
    public static function refundable(Purchase $purchase): int
    {
        if (! $purchase->stripe_payment_intent_id || in_array($purchase->status, [Purchase::STATUS_REFUNDED, Purchase::STATUS_DISPUTED], true)) {
            return 0;
        }

        return max(0, (int) $purchase->amount_total - (int) ($purchase->meta['amount_refunded'] ?? 0));
    }

    /**
     * Refund `$amount` (minor units; null refunds what is left) and record who asked and why.
     */
    public function refund(Purchase $purchase, Account $staff, ?int $amount = null, ?string $reason = null, ?string $note = null): Refund
    {
        if (! $purchase->stripe_payment_intent_id) {
            throw new UnprocessableEntityHttpException('This purchase has no Stripe payment to refund.');
        }
        if ($purchase->status === Purchase::STATUS_REFUNDED) {
            throw new ConflictHttpException('This purchase is already refunded.');
        }
        if ($purchase->status === Purchase::STATUS_DISPUTED) {
            throw new ConflictHttpException('This purchase is disputed. Answer the dispute in the Stripe Dashboard; Stripe does not refund a disputed payment.');
        }

        $left = static::refundable($purchase);
        if ($left === 0) {
            throw new ConflictHttpException('Nothing is left to refund on this purchase.');
        }
        $amount ??= $left;
        if ($amount > $left) {
            throw ValidationException::withMessages(['amount' => 'At most '.number_format($left / 100, 2).' '.$purchase->currency.' can be refunded.']);
        }

        $params = array_filter([
            'payment_intent' => $purchase->stripe_payment_intent_id,
            'amount' => $amount,
            'reason' => $reason,
            'reverse_transfer' => true,
            'refund_application_fee' => true,
            'metadata' => array_filter([
                'purchase' => $purchase->public_id,
                'refunded_by' => $staff->public_id,
                'note' => $note,
            ]),
        ], fn ($value) => $value !== null);

        // A repeated click with the same purchase state and request gets the same refund back.
        $key = 'refund:'.$purchase->uuid.':'.(int) ($purchase->meta['amount_refunded'] ?? 0).':'.sha1(json_encode($params));
        $refund = Stripe::client()->refunds->create($params, ['idempotency_key' => $key]);

        Event::record('purchase.refund_requested', $purchase, $staff, array_filter([
            'refund' => $refund->id,
            'amount' => (int) $refund->amount,
            'currency' => strtoupper((string) $refund->currency),
            'status' => $refund->status,
            'reason' => $reason,
            'note' => $note,
        ], fn ($value) => $value !== null));

        return $refund;
    }
}
