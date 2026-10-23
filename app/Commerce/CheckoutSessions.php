<?php

namespace App\Commerce;

use App\Models\Account;
use App\Models\Checkout;
use App\Models\Extension;
use App\Models\FacilitatorFee;
use App\Models\Instance;
use App\Models\Organization;
use Illuminate\Support\Arr;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Starts a hosted Stripe Checkout Session for a buyer organization: `payment` mode, a destination
 * charge to the publisher's connected account with Fleetbase's facilitator fee as
 * `application_fee_amount`, and Stripe Tax with the publisher liable (the charge is made on its
 * behalf). The checkout row's public id is the hand-off id on the success URL.
 */
class CheckoutSessions
{
    /**
     * @param  array{account?: ?Account, instance?: ?Instance, email?: ?string}  $buyer
     */
    public function start(Extension $extension, Organization $organization, string $source, string $successUrl, string $cancelUrl, array $buyer = []): Checkout
    {
        $extension->loadMissing(['price', 'organization']);
        $this->assertPurchasable($extension, $organization, $buyer['instance'] ?? null);

        $price = $extension->price;
        $publisher = $extension->organization;
        $amount = $price->effective_amount;
        $rate = FacilitatorFee::rateAt(now());
        $fee = FacilitatorFee::feeFor($amount, $rate);
        $expiresAt = now()->addMinutes(max(30, min(1440, (int) config('registry.commerce.checkout_expires_minutes', 60))));

        $checkout = Checkout::create([
            'extension_uuid' => $extension->uuid,
            'organization_uuid' => $organization->uuid,
            'account_uuid' => ($buyer['account'] ?? null)?->uuid,
            'instance_uuid' => ($buyer['instance'] ?? null)?->uuid,
            'source' => $source,
            'status' => Checkout::STATUS_OPEN,
            'amount' => $amount,
            'currency' => $price->currency,
            'fee_amount' => $fee,
            'fee_rate_bps' => $rate,
            'price_snapshot' => [
                'model' => $price->model,
                'amount' => (int) $price->amount,
                'sale_amount' => $price->hasSaleAmount() ? (int) $price->sale_amount : null,
                'on_sale' => $price->isOnSale(),
                'currency' => $price->currency,
                'stripe_price_id' => $price->checkoutStripePriceId(),
            ],
            'expires_at' => $expiresAt,
        ]);

        $metadata = array_filter([
            'handoff' => $checkout->public_id,
            'source' => $source,
            'extension' => $extension->public_id,
            'extension_uuid' => $extension->uuid,
            'organization' => $organization->public_id,
            'organization_uuid' => $organization->uuid,
            'account' => ($buyer['account'] ?? null)?->public_id,
            'instance' => ($buyer['instance'] ?? null)?->public_id,
            'fee_rate_bps' => (string) $rate,
        ]);

        $tax = (bool) config('registry.commerce.tax.enabled', true);
        $params = [
            'mode' => 'payment',
            'line_items' => [['price' => $price->checkoutStripePriceId(), 'quantity' => 1]],
            'success_url' => static::withQuery($successUrl, ['handoff' => $checkout->public_id]),
            'cancel_url' => static::withQuery($cancelUrl, ['handoff' => $checkout->public_id, 'cancelled' => 1]),
            'client_reference_id' => $checkout->public_id,
            'expires_at' => $expiresAt->getTimestamp(),
            'metadata' => $metadata,
            'payment_intent_data' => array_filter([
                'application_fee_amount' => $fee,
                'transfer_data' => ['destination' => $publisher->stripe_connect_id],
                'on_behalf_of' => $tax ? $publisher->stripe_connect_id : null,
                'description' => "{$extension->name} licence",
                'metadata' => $metadata,
            ]),
        ];

        if (filled($buyer['email'] ?? null)) {
            $params['customer_email'] = $buyer['email'];
        }

        if ($tax) {
            $params['automatic_tax'] = ['enabled' => true, 'liability' => ['type' => 'account', 'account' => $publisher->stripe_connect_id]];
            $params['tax_id_collection'] = ['enabled' => true];
        }

        try {
            $session = Stripe::client()->checkout->sessions->create($params, ['idempotency_key' => 'checkout:'.$checkout->uuid]);
        } catch (\Throwable $e) {
            $checkout->delete();

            throw $e;
        }

        $checkout->forceFill([
            'stripe_checkout_session_id' => $session->id,
            'checkout_url' => $session->url,
        ])->save();

        return $checkout;
    }

    /**
     * Refuse a checkout that cannot or need not happen, with the reason the buyer sees.
     */
    protected function assertPurchasable(Extension $extension, Organization $organization, ?Instance $instance): void
    {
        if ($extension->status !== Extension::STATUS_PUBLISHED) {
            throw new UnprocessableEntityHttpException('This extension is not published.');
        }

        if (! $extension->isPaid()) {
            throw new UnprocessableEntityHttpException("{$extension->name} is free: install it without buying.");
        }

        if ($instance && $extension->hosting !== Extension::HOSTING_BOTH && $extension->hosting !== $instance->kind) {
            throw new UnprocessableEntityHttpException("This extension is only available for {$extension->hosting} instances.");
        }

        if ($extension->organization_uuid === $organization->uuid) {
            throw new ConflictHttpException("{$organization->name} publishes {$extension->name} and already has access to it.");
        }

        // Imported listings and accounts (registry:import) have no Stripe state yet: read it now.
        $publisher = $extension->organization;
        if ($publisher?->hasStripeAccount() && $publisher->stripeState() === []) {
            app(ConnectAccounts::class)->refresh($publisher);
        }
        if ($publisher?->canReceivePayments() && ! $extension->price->checkoutStripePriceId()) {
            app(PriceCatalog::class)->sync($extension->price, $extension)->save();
        }

        if (! $publisher?->canReceivePayments() || ! $extension->price->checkoutStripePriceId()) {
            throw new UnprocessableEntityHttpException("{$extension->name} cannot be bought right now: its publisher has not finished setting up payouts.");
        }
    }

    /**
     * Add query parameters to a URL, keeping its own.
     */
    public static function withQuery(string $url, array $query): string
    {
        $fragment = '';
        if (($hash = strpos($url, '#')) !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').Arr::query($query).$fragment;
    }
}
