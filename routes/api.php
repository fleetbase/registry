<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\InstanceCodeController;
use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Catalog\CatalogController;
use App\Http\Controllers\Instance\InstallController;
use App\Http\Controllers\Instance\InstanceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Registry Service API (v1)
|--------------------------------------------------------------------------
|
| Public catalog: no auth.
| Account routes: Authorization: Bearer flbp_… (personal token).
| Instance routes: Authorization: Bearer flbi_… (instance token), except
| `instances/register`, which takes a registration code in the body.
| Package (npm, Composer), publisher, admin and Stripe routes are added in
| later workstreams.
|
*/

Route::prefix('v1')->name('v1.')->group(function () {
    Route::prefix('catalog')->name('catalog.')->middleware('throttle:catalog')->group(function () {
        Route::get('extensions', [CatalogController::class, 'extensions'])->name('extensions');
        Route::get('extensions/{slug}', [CatalogController::class, 'extension'])->name('extension');
        Route::get('extensions/{slug}/releases', [CatalogController::class, 'releases'])->name('releases');
        Route::get('publishers/{slug}', [CatalogController::class, 'publisher'])->name('publisher');
        Route::get('categories', [CatalogController::class, 'categories'])->name('categories');
    });

    Route::prefix('auth')->name('auth.')->middleware('throttle:auth')->group(function () {
        Route::post('register', [AuthController::class, 'register'])->name('register');
        Route::post('verify', [AuthController::class, 'verify'])->name('verify');
        Route::post('verify/resend', [AuthController::class, 'resendVerification'])->name('verify.resend');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:token')->withoutMiddleware('throttle:auth')->name('logout');
    });

    Route::middleware(['auth:token', 'throttle:api'])->group(function () {
        Route::get('account', [AccountController::class, 'show'])->name('account');
        Route::get('account/tokens', [TokenController::class, 'index'])->name('account.tokens.index');
        Route::post('account/tokens', [TokenController::class, 'store'])->name('account.tokens.store');
        Route::delete('account/tokens/{token}', [TokenController::class, 'destroy'])->name('account.tokens.destroy');
        Route::post('account/instance-codes', [InstanceCodeController::class, 'store'])->middleware('scope:instance:register')->name('account.instance-codes.store');
    });

    Route::prefix('instances')->name('instances.')->group(function () {
        Route::post('register', [InstanceController::class, 'register'])->middleware('throttle:instance-register')->name('register');

        Route::middleware(['auth:instance', 'throttle:instances'])->group(function () {
            Route::get('me', [InstanceController::class, 'me'])->name('me');
            Route::get('me/updates', [InstanceController::class, 'updates'])->name('me.updates');
            Route::post('me/installs', [InstallController::class, 'store'])->name('me.installs.store');
            Route::delete('me/installs/{extension}', [InstallController::class, 'destroy'])->where('extension', '.+')->name('me.installs.destroy');
        });
    });
});
