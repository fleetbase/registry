<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Catalog\CatalogController;
use App\Http\Controllers\Packages\ComposerController;
use App\Http\Controllers\Packages\NpmController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Registry Service API (v1)
|--------------------------------------------------------------------------
|
| Public catalog: no auth.
| Account routes: Authorization: Bearer flbp_… (personal token).
| Package routes (/npm, /composer) sit outside /v1 because npm and Composer
| clients address them as registry roots.
| Instance, publisher, admin and Stripe routes are added in later workstreams.
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
    });
});

Route::prefix('npm')->name('npm.')->middleware('throttle:packages')->group(function () {
    Route::get('-/ping', [NpmController::class, 'ping'])->name('ping');
    Route::get('-/whoami', [NpmController::class, 'whoami'])->name('whoami');

    Route::get('{package}/-/{file}', [NpmController::class, 'tarball'])
        ->middleware('licence:npm')
        ->where(['package' => NpmController::NAME_PATTERN, 'file' => '[A-Za-z0-9._~+-]+\\.tgz'])
        ->name('tarball');

    Route::get('{package}', [NpmController::class, 'packument'])
        ->middleware('licence:npm')
        ->where('package', NpmController::NAME_PATTERN)
        ->name('packument');

    Route::match(['PUT', 'POST', 'DELETE', 'PATCH'], '{any}', [NpmController::class, 'readOnly'])
        ->where('any', '.*')
        ->name('read-only');
});

Route::prefix('composer')->name('composer.')->middleware('throttle:packages')->group(function () {
    Route::get('packages.json', [ComposerController::class, 'packages'])->name('packages');

    Route::get('p2/{vendor}/{package}.json', [ComposerController::class, 'versions'])
        ->middleware('licence:composer')
        ->where(['vendor' => ComposerController::VENDOR_PATTERN, 'package' => ComposerController::PACKAGE_PATTERN.'(?:~dev)?'])
        ->name('versions');

    Route::get('dist/{vendor}/{package}/{version}.tar', [ComposerController::class, 'dist'])
        ->middleware('licence:composer')
        ->where(['vendor' => ComposerController::VENDOR_PATTERN, 'package' => ComposerController::PACKAGE_PATTERN, 'version' => '[A-Za-z0-9.+_-]+'])
        ->name('dist');
});
