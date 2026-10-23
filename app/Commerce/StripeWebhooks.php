<?php

namespace App\Commerce;

use App\Mail\DisputeOpenedMail;
use App\Mail\PurchaseReceiptMail;
use App\Models\Checkout;
use App\Models\Event;
use App\Models\Extension;
use App\Models\Licence;
use App\Models\Organization;
use App\Models\Purchase;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Account as StripeAccount;
use Stripe\Charge;
use Stripe\Checkout\Session;
use Stripe\Dispute;
use Stripe\Event as StripeEvent;

/**
 * Handles verified Stripe events. Each event id is stored once in `events.external_id`, in the
 * same transaction as its effects, so a redelivery is a no-op and a failure (answered 500) is
 * retried by Stripe with nothing half-written.
 */
class StripeWebhooks
{
    public const PROCESSED = 'processed';

    public const DUPLICATE = 'duplicate';

    public const IGNORED = 'ignored';

    public const HANDLED = [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.expired',
        'charge.refunded',
        'charge.dispute.created',
        'account.updated',
    ];

    /** Mail to send once the transaction commits. */
    protected array $mail = [];

    public function handle(StripeEvent $event): string
    {
        if (! in_array($event->type, self::HANDLED, true)) {
            return self::IGNORED;
        }

        if (Event::query()->where('external_id', $event->id)->exists()) {
            return self::DUPLICATE;
        }

        $this->mail = [];

        try {
            DB::transaction(function () use ($event) {
                Event::create([
                    'kind' => 'stripe.'.$event->type,
                    'external_id' => $event->id,
                    'payload' => array_filter([
                        'object' => $event->data->object->id ?? null,
                        'account' => $event->account ?? null,
                        'livemode' => (bool) $event->livemode,
                    ], fn ($value) => $value !== null),
                ]);

                $object = $event->data->object;
                match ($event->type) {
                    'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->completed($object),
                    'checkout.session.expired' => $this->expired($object),
                    'charge.refunded' => $this->refunded($object),
                    'charge.dispute.created' => $this->disputed($object),
                    'account.updated' => $this->accountUpdated($object),
                };
            });
        } catch (UniqueConstraintViolationException) {
            // Delivered twice at once: the other delivery stored the event.
            return self::DUPLICATE;
        }

        foreach ($this->mail as [$to, $mailable]) {
            rescue(fn () => Mail::to($to)->send($mailable));
        }

        return self::PROCESSED;
    }

    /**
     * A paid session creates the purchase and a paid licence, and completes the hand-off.
     * Sessions paid later (bank debits) wait for `async_payment_succeeded`.
     */
    protected function completed(Session $session): void
    {
        if (! in_array($session->payment_status, ['paid', 'no_payment_required'], true)) {
            return;
        }

        if (Purchase::query()->where('stripe_checkout_session_id', $session->id)->exists()) {
            return;
        }

        $checkout = Checkout::query()->where('stripe_checkout_session_id', $session->id)->lockForUpdate()->first()
            ?? Checkout::findById($session->metadata['handoff'] ?? null);
        $extension = Extension::find($checkout?->extension_uuid ?? ($session->metadata['extension_uuid'] ?? null));
        $organization = Organization::find($checkout?->organization_uuid ?? ($session->metadata['organization_uuid'] ?? null));

        if (! $extension || ! $organization) {
            Log::warning('Stripe checkout session without a registry checkout', ['session' => $session->id]);

            return;
        }

        $intent = $session->payment_intent
            ? Stripe::client()->paymentIntents->retrieve(is_string($session->payment_intent) ? $session->payment_intent : $session->payment_intent->id, ['expand' => ['latest_charge']])
            : null;
        $charge = $intent?->latest_charge instanceof Charge ? $intent->latest_charge : null;

        $total = (int) $session->amount_total;
        $fee = (int) ($intent?->application_fee_amount ?? $checkout?->fee_amount ?? 0);
        $email = $session->customer_details['email'] ?? $session->customer_email ?? null;

        $purchase = Purchase::create([
            'extension_uuid' => $extension->uuid,
            'buyer_organization_uuid' => $organization->uuid,
            'buyer_account_uuid' => $checkout?->account_uuid,
            'price_snapshot' => $checkout?->price_snapshot,
            'amount_total' => $total,
            'fee_amount' => $fee,
            'net_amount' => $total - $fee,
            'currency' => strtoupper((string) $session->currency),
            'stripe_checkout_session_id' => $session->id,
            'stripe_payment_intent_id' => $intent?->id,
            'status' => Purchase::STATUS_PAID,
            'receipt_url' => $charge?->receipt_url,
            'meta' => array_filter([
                'handoff' => $checkout?->public_id,
                'source' => $checkout?->source,
                'instance_uuid' => $checkout?->instance_uuid,
                'amount_subtotal' => (int) $session->amount_subtotal,
                'amount_tax' => (int) ($session->total_details['amount_tax'] ?? 0),
                'fee_rate_bps' => $checkout?->fee_rate_bps ?? (int) ($session->metadata['fee_rate_bps'] ?? 0),
                'customer_email' => $email,
                'stripe_charge_id' => $charge?->id,
                'stripe_account' => $intent?->transfer_data['destination'] ?? null,
            ], fn ($value) => $value !== null),
        ]);

        $licence = Licence::create([
            'extension_uuid' => $extension->uuid,
            'organization_uuid' => $organization->uuid,
            'purchase_uuid' => $purchase->uuid,
            'kind' => Licence::KIND_PAID,
            'max_activations' => (int) config('registry.commerce.max_activations', 5),
            'status' => Licence::STATUS_ACTIVE,
        ]);

        $checkout?->forceFill([
            'status' => Checkout::STATUS_COMPLETE,
            'purchase_uuid' => $purchase->uuid,
            'completed_at' => now(),
        ])->save();

        Event::record('purchase.completed', $purchase, $checkout?->account, [
            'extension' => $extension->public_id,
            'organization' => $organization->public_id,
            'licence' => $licence->public_id,
            'amount_total' => $total,
            'fee_amount' => $fee,
        ]);

        if ($email) {
            $this->mail[] = [$email, new PurchaseReceiptMail($purchase)];
        }
    }

    protected function expired(Session $session): void
    {
        Checkout::query()
            ->where('stripe_checkout_session_id', $session->id)
            ->where('status', Checkout::STATUS_OPEN)
            ->update(['status' => Checkout::STATUS_EXPIRED, 'updated_at' => now()]);
    }

    /**
     * A full refund marks the purchase refunded and revokes its licence (new installs and updates
     * stop; running instances keep working). A partial refund is recorded on the purchase only.
     */
    protected function refunded(Charge $charge): void
    {
        $purchase = $this->purchaseFor($charge->payment_intent);
        if (! $purchase) {
            return;
        }

        $meta = array_merge((array) $purchase->meta, ['amount_refunded' => (int) $charge->amount_refunded]);

        if (! $charge->refunded) {
            $purchase->forceFill(['meta' => $meta])->save();
            Event::record('purchase.partially_refunded', $purchase, null, ['amount_refunded' => (int) $charge->amount_refunded]);

            return;
        }

        $purchase->forceFill([
            'status' => Purchase::STATUS_REFUNDED,
            'meta' => $meta + ['refunded_at' => now()->toIso8601String()],
        ])->save();

        $purchase->licence()->where('status', Licence::STATUS_ACTIVE)->get()->each(function (Licence $licence) {
            $licence->forceFill([
                'status' => Licence::STATUS_REVOKED,
                'meta' => array_merge((array) $licence->meta, ['revoked_reason' => 'refunded', 'revoked_at' => now()->toIso8601String()]),
            ])->save();
        });

        Event::record('purchase.refunded', $purchase, null, ['amount_refunded' => (int) $charge->amount_refunded]);
    }

    /**
     * A dispute flags the purchase (the licence stays active while it is open) and tells staff.
     */
    protected function disputed(Dispute $dispute): void
    {
        $purchase = $this->purchaseFor($dispute->payment_intent);
        if (! $purchase) {
            return;
        }

        $purchase->forceFill([
            'status' => $purchase->status === Purchase::STATUS_REFUNDED ? $purchase->status : Purchase::STATUS_DISPUTED,
            'meta' => array_merge((array) $purchase->meta, [
                'dispute' => [
                    'id' => $dispute->id,
                    'reason' => $dispute->reason,
                    'status' => $dispute->status,
                    'amount' => (int) $dispute->amount,
                    'opened_at' => now()->toIso8601String(),
                ],
            ]),
        ])->save();

        Event::record('purchase.disputed', $purchase, null, ['dispute' => $dispute->id, 'reason' => $dispute->reason]);

        foreach ((array) config('registry.commerce.notify', []) as $to) {
            $this->mail[] = [$to, new DisputeOpenedMail($purchase)];
        }
    }

    protected function accountUpdated(StripeAccount $account): void
    {
        $organization = Organization::query()->where('stripe_connect_id', $account->id)->first();

        if ($organization) {
            app(ConnectAccounts::class)->sync($organization, $account);
        }
    }

    protected function purchaseFor(mixed $paymentIntent): ?Purchase
    {
        $id = is_string($paymentIntent) ? $paymentIntent : ($paymentIntent->id ?? null);

        return $id ? Purchase::query()->where('stripe_payment_intent_id', $id)->lockForUpdate()->first() : null;
    }
}
