<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Stripe\ApiRequestor;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Website pages render the Blade root view; tests do not need built assets.
        $this->withoutVite();
    }

    protected function tearDown(): void
    {
        // A test that faked Stripe (tests/Helpers/stripe.php) must not leak it into the next.
        ApiRequestor::setHttpClient(null);

        parent::tearDown();
    }

    /**
     * Each simulated request resolves auth and its session from scratch, as separate HTTP
     * requests would (the session comes back only through its cookie and the store).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();
        // session.store and the redirector hold the previous driver; point them at the new one
        // so values such as url.intended reach the next request.
        $this->app->forgetInstance('session.store');
        $this->app['redirect']->setSession($this->app['session.store']);

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
