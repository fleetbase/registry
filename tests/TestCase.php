<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Each simulated request resolves auth and its session from scratch, as separate HTTP
     * requests would (the session comes back only through its cookie and the store).
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }
}
