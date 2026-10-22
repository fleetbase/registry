<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\InstanceCodeController;
use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Catalog\CatalogController;
use App\Http\Controllers\Instance\InstallController;
use App\Http\Controllers\Instance\InstanceController;
use App\Http\Controllers\Packages\ComposerController;
use App\Http\Controllers\Packages\NpmController;
use App\Http\Controllers\Publisher\ExtensionController as PublisherExtensionController;
use App\Http\Controllers\Publisher\ReleaseController as PublisherReleaseController;
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
| Instance routes: Authorization: Bearer flbi_… (instance token), except
| `instances/register`, which takes a registration code in the body.
| Publisher routes: personal token with the `publish` scope and the owner, admin or
| publisher role in the listing's organization.
| Admin routes: personal token with the `review` scope, member of an `is_staff` organization.
| Stripe routes are added in a later workstream.
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

    Route::prefix('publisher')->name('publisher.')->middleware(['auth:token', 'throttle:publish', 'scope:publish'])->group(function () {
        Route::get('extensions', [PublisherExtensionController::class, 'index'])->name('extensions.index');
        Route::post('extensions', [PublisherExtensionController::class, 'store'])->name('extensions.store');
        Route::get('extensions/{slug}', [PublisherExtensionController::class, 'show'])->name('extensions.show');
        Route::patch('extensions/{slug}', [PublisherExtensionController::class, 'update'])->name('extensions.update');
        Route::get('extensions/{slug}/releases', [PublisherReleaseController::class, 'index'])->name('releases.index');
        Route::post('extensions/{slug}/releases', [PublisherReleaseController::class, 'store'])->middleware('throttle:upload')->name('releases.store');
        Route::get('releases/{release}', [PublisherReleaseController::class, 'show'])->name('releases.show');
        Route::post('releases/{release}/submit', [PublisherReleaseController::class, 'submit'])->name('releases.submit');
        Route::post('releases/{release}/withdraw', [PublisherReleaseController::class, 'withdraw'])->name('releases.withdraw');
        Route::post('releases/{release}/yank', [PublisherReleaseController::class, 'yank'])->name('releases.yank');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:token', 'throttle:api', 'scope:review', 'staff'])->group(function () {
        Route::get('releases', [ReviewController::class, 'queue'])->name('releases.queue');
        Route::get('releases/{release}', [ReviewController::class, 'show'])->name('releases.show');
        Route::post('releases/{release}/claim', [ReviewController::class, 'claim'])->name('releases.claim');
        Route::post('releases/{release}/approve', [ReviewController::class, 'approve'])->name('releases.approve');
        Route::post('releases/{release}/reject', [ReviewController::class, 'reject'])->name('releases.reject');
        Route::post('releases/{release}/publish', [ReviewController::class, 'publish'])->name('releases.publish');
        Route::post('extensions/{slug}/suspend', [ReviewController::class, 'suspend'])->name('extensions.suspend');
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
