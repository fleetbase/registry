<?php

use App\Models\Account;
use App\Models\Instance;

return [

    /*
    |--------------------------------------------------------------------------
    | Authentication Defaults
    |--------------------------------------------------------------------------
    |
    | API clients authenticate with a bearer token. The `token` guard resolves
    | personal tokens (flbp_) and the `instance` guard resolves instance tokens
    | (flbi_), both through App\Auth\TokenGuard. The `web` guard resolves the
    | publisher website's cookie session to its `flbs_` session token through
    | App\Auth\SessionGuard (see App\Http\Middleware\StatefulSession).
    |
    */

    'defaults' => [
        'guard' => env('AUTH_GUARD', 'token'),
        'passwords' => env('AUTH_PASSWORD_BROKER', 'accounts'),
    ],

    'guards' => [
        'token' => [
            'driver' => 'registry-token',
            'provider' => 'accounts',
        ],
        'instance' => [
            'driver' => 'registry-instance-token',
            'provider' => 'instances',
        ],
        'web' => [
            'driver' => 'registry-session',
            'provider' => 'accounts',
        ],
    ],

    'providers' => [
        'accounts' => [
            'driver' => 'eloquent',
            'model' => Account::class,
        ],
        'instances' => [
            'driver' => 'eloquent',
            'model' => Instance::class,
        ],
    ],

    'passwords' => [
        'accounts' => [
            'provider' => 'accounts',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
