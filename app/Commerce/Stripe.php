<?php

namespace App\Commerce;

use Stripe\StripeClient;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * The service's Stripe client. Commerce routes answer 503 until `services.stripe.secret` is set.
 */
class Stripe
{
    public static function configured(): bool
    {
        return filled(config('services.stripe.secret'));
    }

    public static function client(): StripeClient
    {
        if (! static::configured()) {
            throw new ServiceUnavailableHttpException(null, 'Payments are not set up on this registry.');
        }

        return app(StripeClient::class);
    }

    /**
     * Request options that act on a connected account.
     */
    public static function onAccount(string $account): array
    {
        return ['stripe_account' => $account];
    }
}
