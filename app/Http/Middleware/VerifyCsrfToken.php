<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

/**
 * Laravel's CSRF check for cookie-session requests (see StatefulSession). The website sends
 * the `XSRF-TOKEN` cookie back as `X-XSRF-TOKEN`. Unlike the framework default it also runs
 * under unit tests, so the session tests exercise the real check.
 */
class VerifyCsrfToken extends ValidateCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
