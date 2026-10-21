<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Auth\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Registry Service API (v1)
|--------------------------------------------------------------------------
|
| Public catalog: no auth.
| Account routes: Authorization: Bearer flbp_… (personal token).
| Package (npm, Composer), instance, publisher, admin and Stripe routes
| are added in later workstreams.
|
*/

Route::prefix('v1')->name('v1.')->group(function () {
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
    });
});
