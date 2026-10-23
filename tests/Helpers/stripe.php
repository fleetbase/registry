<?php

use Illuminate\Testing\TestResponse;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

/**
 * Stands in for Stripe's HTTP API under stripe-php: the real client builds requests and parses
 * responses, and this answers them from JSON fixtures (tests/Fixtures/stripe) instead of the
 * network, recording every request so tests can assert what was sent.
 */
class FakeStripeHttpClient implements ClientInterface
{
    /** @var list<array{method: string, pattern: string, response: array|callable, status: int, times: ?int}> */
    protected array $routes = [];

    /** @var list<array{method: string, path: string, params: array, headers: array}> */
    public array $requests = [];

    /**
     * Answer `METHOD /v1/path` (a regex on the path, anchored) with an object or a callable that
     * receives the params and returns one. Later routes win, so a test can override a default.
     */
    public function on(string $method, string $path, array|callable $response, int $status = 200, ?int $times = null): static
    {
        array_unshift($this->routes, ['method' => strtolower($method), 'pattern' => '#^'.$path.'$#', 'response' => $response, 'status' => $status, 'times' => $times]);

        return $this;
    }

    public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null)
    {
        $path = (string) parse_url($absUrl, PHP_URL_PATH);
        $this->requests[] = ['method' => $method, 'path' => $path, 'params' => (array) $params, 'headers' => $headers];

        foreach ($this->routes as $index => $route) {
            if ($route['method'] !== $method || ! preg_match($route['pattern'], $path)) {
                continue;
            }
            if ($route['times'] !== null) {
                if ($route['times'] === 0) {
                    continue;
                }
                $this->routes[$index]['times']--;
            }

            $body = is_callable($route['response']) ? ($route['response'])((array) $params, $path) : $route['response'];

            return [json_encode($body), $route['status'], ['request-id' => 'req_test']];
        }

        throw new RuntimeException("Unexpected Stripe request: {$method} {$path}");
    }

    /**
     * Recorded requests, optionally for one method and path regex.
     *
     * @return list<array{method: string, path: string, params: array, headers: array}>
     */
    public function sent(?string $method = null, ?string $path = null): array
    {
        return array_values(array_filter($this->requests, fn ($request) => ($method === null || $request['method'] === strtolower($method))
            && ($path === null || preg_match('#^'.$path.'$#', $request['path']))));
    }

    /**
     * The value of a header on a recorded request (`Stripe-Account`, `Idempotency-Key`).
     */
    public static function header(array $request, string $name): ?string
    {
        foreach ($request['headers'] as $line) {
            [$key, $value] = array_map('trim', explode(':', $line, 2)) + [1 => null];
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}

const STRIPE_TEST_WEBHOOK_SECRET = 'whsec_test_platform';

/**
 * Configure Stripe for a test and route stripe-php to a fresh fake. Reset in Pest.php.
 */
function fakeStripe(): FakeStripeHttpClient
{
    config([
        'services.stripe.key' => 'pk_test_registry',
        'services.stripe.secret' => 'sk_test_registry',
        'services.stripe.webhook_secrets' => [STRIPE_TEST_WEBHOOK_SECRET, 'whsec_test_connect'],
    ]);

    $fake = new FakeStripeHttpClient;
    $id = fn (string $prefix) => $prefix.'_test'.bin2hex(random_bytes(6));

    // Defaults; a test overrides any of them with a later `on()`.
    $fake->on('post', '/v1/accounts', fn ($params) => stripeFixture('account_pending', ['id' => $id('acct'), 'email' => $params['email'] ?? null]))
        ->on('get', '/v1/accounts/(acct_\w+)', fn ($params, $path) => stripeFixture('account', ['id' => basename($path)]))
        ->on('post', '/v1/account_sessions', fn ($params) => stripeFixture('account_session', ['account' => $params['account']]))
        ->on('post', '/v1/products', fn ($params) => stripeFixture('product', ['id' => $id('prod'), 'name' => $params['name'] ?? 'Extension', 'tax_code' => $params['tax_code'] ?? null]))
        ->on('post', '/v1/products/(prod_\w+)', fn ($params, $path) => stripeFixture('product', ['id' => basename($path)] + $params))
        ->on('post', '/v1/prices', fn ($params) => stripeFixture('price', [
            'id' => $id('price'),
            'product' => $params['product'],
            'unit_amount' => (int) $params['unit_amount'],
            'unit_amount_decimal' => (string) $params['unit_amount'],
            'currency' => $params['currency'],
            'metadata' => $params['metadata'] ?? [],
        ]))
        ->on('post', '/v1/prices/(price_\w+)', fn ($params, $path) => stripeFixture('price', ['id' => basename($path), 'active' => (bool) ($params['active'] ?? true)]))
        ->on('post', '/v1/checkout/sessions', function ($params) use ($id) {
            $session = $id('cs');

            return stripeFixture('checkout_session', [
                'id' => $session,
                'status' => 'open',
                'payment_status' => 'unpaid',
                'payment_intent' => null,
                'amount_total' => null,
                'customer_details' => null,
                'customer_email' => $params['customer_email'] ?? null,
                'metadata' => $params['metadata'] ?? [],
                'client_reference_id' => $params['client_reference_id'] ?? null,
                'success_url' => $params['success_url'],
                'cancel_url' => $params['cancel_url'],
                'expires_at' => $params['expires_at'] ?? null,
                'url' => 'https://checkout.stripe.com/c/pay/'.$session,
            ]);
        })
        ->on('get', '/v1/payment_intents/(pi_\w+)', fn ($params, $path) => stripeFixture('payment_intent', ['id' => basename($path)]))
        ->on('get', '/v1/balance', stripeFixture('balance'))
        ->on('get', '/v1/payouts', stripeFixture('payouts'));

    ApiRequestor::setHttpClient($fake);
    app()->instance(FakeStripeHttpClient::class, $fake);

    return $fake;
}

function stripe(): FakeStripeHttpClient
{
    return app(FakeStripeHttpClient::class);
}

/**
 * A Stripe API object from tests/Fixtures/stripe/{name}.json with overrides merged in.
 */
function stripeFixture(string $name, array $overrides = []): array
{
    $fixture = json_decode(file_get_contents(base_path("tests/Fixtures/stripe/{$name}.json")), true, flags: JSON_THROW_ON_ERROR);

    return array_replace_recursive($fixture, $overrides);
}

/**
 * Post a signed event to the webhook endpoint, as Stripe does.
 */
function stripeWebhook(string $type, array $object, array $event = [], ?string $secret = STRIPE_TEST_WEBHOOK_SECRET, ?int $timestamp = null): TestResponse
{
    $payload = json_encode(array_replace(stripeFixture('event', [
        'id' => 'evt_'.bin2hex(random_bytes(8)),
        'type' => $type,
        'created' => time(),
    ]), ['data' => ['object' => $object]], $event));

    $timestamp ??= time();
    $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, (string) $secret);

    return test()->call('POST', '/v1/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], $payload);
}
