<?php

use App\Http\Controllers\WebsiteController;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/*
|--------------------------------------------------------------------------
| Publisher website
|--------------------------------------------------------------------------
|
| Every other GET path serves the Ember app's public/index.html; the app routes on the
| client. The shell needs no session: the app calls GET /v1/auth/session for its cookies.
| /v1, /npm, /composer and /up keep their JSON 404s. The service info moved to GET /v1.
|
*/

Route::any('{path?}', WebsiteController::class)
    ->where('path', '.*')
    ->withoutMiddleware([EncryptCookies::class, AddQueuedCookiesToResponse::class, StartSession::class, ShareErrorsFromSession::class, ValidateCsrfToken::class])
    ->fallback()
    ->name('website');
