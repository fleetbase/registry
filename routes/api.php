<?php

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\InstanceCodeController;
use App\Http\Controllers\Account\TokenController;
use App\Http\Controllers\Admin\ReviewController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Catalog\CatalogController;
use App\Http\Controllers\Instance\InstallController;
use App\Http\Controllers\Instance\InstanceController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Packages\ComposerController;
use App\Http\Controllers\Packages\NpmController;
use App\Http\Controllers\Publisher\ExtensionController as PublisherExtensionController;
use App\Http\Controllers\Publisher\ListingMediaController;
use App\Http\Controllers\Publisher\ReleaseController as PublisherReleaseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Registry Service API (v1)
|--------------------------------------------------------------------------
|
| Public catalog: no auth.
| Account routes: Authorization: Bearer flbp_… (personal token), or the publisher
| website's cookie session (`web` guard: StatefulSession + CSRF, see auth/session).
| Package routes (/npm, /composer) sit outside /v1 because npm and Composer
| clients address them as registry roots.
| Instance routes: Authorization: Bearer flbi_… (instance token), except
| `instances/register`, which takes a registration code in the body.
| Publisher routes: personal token (or website session) with the `publish` scope and the
| owner, admin or publisher role in the listing's organization.
| Admin routes: personal token (or website session) with the `review` scope, member of an
| `is_staff` organization.
| Stripe routes are added in a later workstream.
|
*/

Route::prefix('v1')->name('v1.')->middleware('stateful')->group(function () {
    Route::get('/', fn () => response()->json([
        'service' => config('app.name'),
        'version' => config('registry.version'),
        'docs' => 'https://docs.fleetbase.io/registry',
        'catalog' => url('/v1/catalog/extensions'),
    ]))->withoutMiddleware('stateful')->name('info');

    // Public and cached: never starts a session.
    Route::prefix('catalog')->name('catalog.')->middleware('throttle:catalog')->withoutMiddleware('stateful')->group(function () {
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

        // The publisher website's cookie session. Always stateful; POST and DELETE need X-XSRF-TOKEN.
        Route::get('session', [SessionController::class, 'show'])->withoutMiddleware('throttle:auth')->middleware('throttle:api')->name('session.show');
        Route::post('session', [SessionController::class, 'store'])->name('session.store');
        Route::delete('session', [SessionController::class, 'destroy'])->withoutMiddleware('throttle:auth')->middleware('throttle:api')->name('session.destroy');
    });

    Route::middleware(['auth:token,web', 'throttle:api'])->group(function () {
        Route::get('account', [AccountController::class, 'show'])->name('account');
        Route::patch('account', [AccountController::class, 'update'])->name('account.update');
        Route::post('account/password', [AccountController::class, 'password'])->name('account.password');
        Route::get('account/tokens', [TokenController::class, 'index'])->name('account.tokens.index');
        Route::post('account/tokens', [TokenController::class, 'store'])->name('account.tokens.store');
        Route::delete('account/tokens/{token}', [TokenController::class, 'destroy'])->name('account.tokens.destroy');
        Route::post('account/instance-codes', [InstanceCodeController::class, 'store'])->middleware('scope:instance:register')->name('account.instance-codes.store');

        Route::get('organizations', [OrganizationController::class, 'index'])->name('organizations.index');
        Route::post('organizations', [OrganizationController::class, 'store'])->name('organizations.store');
        Route::get('organizations/{slug}', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::patch('organizations/{slug}', [OrganizationController::class, 'update'])->name('organizations.update');
        Route::post('organizations/{slug}/members', [OrganizationController::class, 'addMember'])->name('organizations.members.store');
        Route::patch('organizations/{slug}/members/{member}', [OrganizationController::class, 'updateMember'])->name('organizations.members.update');
        Route::delete('organizations/{slug}/members/{member}', [OrganizationController::class, 'removeMember'])->name('organizations.members.destroy');
    });

    Route::prefix('publisher')->name('publisher.')->middleware(['auth:token,web', 'throttle:publish', 'scope:publish'])->group(function () {
        Route::get('extensions', [PublisherExtensionController::class, 'index'])->name('extensions.index');
        Route::post('extensions', [PublisherExtensionController::class, 'store'])->name('extensions.store');
        Route::get('extensions/{slug}', [PublisherExtensionController::class, 'show'])->name('extensions.show');
        Route::patch('extensions/{slug}', [PublisherExtensionController::class, 'update'])->name('extensions.update');
        Route::put('extensions/{slug}/price', [PublisherExtensionController::class, 'price'])->name('extensions.price');
        Route::post('extensions/{slug}/icon', [ListingMediaController::class, 'storeIcon'])->middleware('throttle:upload')->name('extensions.icon.store');
        Route::delete('extensions/{slug}/icon', [ListingMediaController::class, 'destroyIcon'])->name('extensions.icon.destroy');
        Route::post('extensions/{slug}/screenshots', [ListingMediaController::class, 'storeScreenshot'])->middleware('throttle:upload')->name('extensions.screenshots.store');
        Route::patch('extensions/{slug}/screenshots', [ListingMediaController::class, 'reorderScreenshots'])->name('extensions.screenshots.reorder');
        Route::delete('extensions/{slug}/screenshots/{file}', [ListingMediaController::class, 'destroyScreenshot'])->name('extensions.screenshots.destroy');
        Route::get('extensions/{slug}/releases', [PublisherReleaseController::class, 'index'])->name('releases.index');
        Route::post('extensions/{slug}/releases', [PublisherReleaseController::class, 'store'])->middleware('throttle:upload')->name('releases.store');
        Route::get('releases/{release}', [PublisherReleaseController::class, 'show'])->name('releases.show');
        Route::post('releases/{release}/submit', [PublisherReleaseController::class, 'submit'])->name('releases.submit');
        Route::post('releases/{release}/withdraw', [PublisherReleaseController::class, 'withdraw'])->name('releases.withdraw');
        Route::post('releases/{release}/yank', [PublisherReleaseController::class, 'yank'])->name('releases.yank');
    });

    Route::prefix('admin')->name('admin.')->middleware(['auth:token,web', 'throttle:api', 'scope:review', 'staff'])->group(function () {
        Route::get('review-items', [ReviewController::class, 'reviewItems'])->name('review-items');
        Route::get('releases', [ReviewController::class, 'queue'])->name('releases.queue');
        Route::get('releases/{release}', [ReviewController::class, 'show'])->name('releases.show');
        Route::get('releases/{release}/files/{kind}', [ReviewController::class, 'download'])->where('kind', 'engine|api|dist')->name('releases.files.download');
        Route::post('releases/{release}/claim', [ReviewController::class, 'claim'])->name('releases.claim');
        Route::post('releases/{release}/approve', [ReviewController::class, 'approve'])->name('releases.approve');
        Route::post('releases/{release}/reject', [ReviewController::class, 'reject'])->name('releases.reject');
        Route::post('releases/{release}/publish', [ReviewController::class, 'publish'])->name('releases.publish');
        Route::post('extensions/{slug}/suspend', [ReviewController::class, 'suspend'])->name('extensions.suspend');
        Route::post('extensions/{slug}/unsuspend', [ReviewController::class, 'unsuspend'])->name('extensions.unsuspend');
    });

    // Instances authenticate with flbi_ tokens only: never a session or CSRF.
    Route::prefix('instances')->name('instances.')->withoutMiddleware('stateful')->group(function () {
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
