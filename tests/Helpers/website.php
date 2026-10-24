<?php

use App\Auth\SessionGuard;
use App\Models\Account;
use App\Models\Token;
use Illuminate\Support\Str;

/**
 * Sign the account in to the website for the following requests, as POST /v1/auth/session does:
 * a server-side `flbs_` token whose id is stored in a saved session, sent as the session cookie.
 */
function actingOnWebsite(Account $account): Token
{
    $token = Token::issue($account, 'web session (test)', [Token::SCOPE_ALL], now()->addHours(2), null, Token::PREFIX_SESSION);

    // The array store is per driver instance, and TestCase rebuilds drivers on every request.
    config(['session.driver' => 'database']);
    app('session')->forgetDrivers();

    $session = app('session')->driver();
    $session->setId(Str::random(40));
    $session->start();
    $session->put(SessionGuard::SESSION_KEY, $token->uuid);
    $session->save();

    test()->withCookie(config('session.cookie'), $session->getId());

    return $token;
}
