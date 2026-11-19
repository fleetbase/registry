<?php

use App\Models\Account;
use Illuminate\Testing\TestResponse;

/**
 * Cookies a response sets, merged over the ones the client already holds (as a browser
 * keeps them). Values stay encrypted, exactly as the browser receives them.
 */
function cookiesFrom(TestResponse $response, array $cookies = []): array
{
    foreach ($response->headers->getCookies() as $cookie) {
        $cookies[$cookie->getName()] = $cookie->getValue();
    }

    return $cookies;
}

/**
 * A JSON request from the website: the given cookies and, unless `$csrf` is false, the
 * `X-XSRF-TOKEN` header copied from the `XSRF-TOKEN` cookie as the SPA sends it.
 */
function websiteRequest(string $method, string $uri, array $cookies, array $data = [], bool $csrf = true): TestResponse
{
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($csrf && isset($cookies['XSRF-TOKEN'])) {
        $server['HTTP_X_XSRF_TOKEN'] = $cookies['XSRF-TOKEN'];
    }

    return test()->call($method, $uri, [], $cookies, [], $server, $data === [] ? null : json_encode($data));
}

/**
 * Sign in through the website session (GET then POST /v1/auth/session) and return the
 * browser's cookies afterwards. The account's password must be `password`.
 */
function websiteSignIn(Account $account, string $password = 'password'): array
{
    $cookies = cookiesFrom(websiteRequest('GET', '/v1/auth/session', [])->assertOk());
    $response = websiteRequest('POST', '/v1/auth/session', $cookies, ['email' => $account->email, 'password' => $password])->assertOk();

    return cookiesFrom($response, $cookies);
}
