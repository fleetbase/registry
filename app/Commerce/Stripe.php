<?php

namespace App\Commerce;

use Stripe\Exception\ApiErrorException;
use Stripe\Exception\CardException;
use Stripe\Exception\InvalidRequestException;
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
     * What to tell the caller about a Stripe failure: Stripe's own words for a refused request
     * (a bad price, an incomplete account), a generic line when the platform's side failed
     * (credentials, network, rate limits), which callers cannot fix.
     */
    public static function failure(ApiErrorException $e): string
    {
        return $e instanceof InvalidRequestException || $e instanceof CardException
            ? 'Stripe could not complete the request: '.$e->getMessage()
            : 'Payments are unavailable right now. Try again in a few minutes.';
    }

    /**
     * Request options that act on a connected account.
     */
    public static function onAccount(string $account): array
    {
        return ['stripe_account' => $account];
    }
}
