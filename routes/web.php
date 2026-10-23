<?php

use App\Http\Controllers\Auth\GithubSignInController;
use App\Http\Controllers\Web\AccountPageController;
use App\Http\Controllers\Web\CatalogPageController;
use App\Http\Controllers\Web\ReviewPageController;
use App\Http\Controllers\Web\StudioPageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Website (Inertia)
|--------------------------------------------------------------------------
|
| The registry website: public catalog pages, account pages, the publisher Studio and the
| staff review console. Pages are rendered with Inertia (React) from the same controllers that
| serve /v1; writes go from the browser to /v1 with the session cookie and XSRF header.
| /v1, /npm, /composer and /up are API routes and never reach this file's fallback.
|
*/

Route::get('/', [CatalogPageController::class, 'home'])->name('home');
Route::get('extensions/{slug}', [CatalogPageController::class, 'extension'])->name('extensions.show');
Route::get('publishers/{slug}', [CatalogPageController::class, 'publisher'])->name('publishers.show');
Route::post('console', [CatalogPageController::class, 'console'])->name('console');

Route::get('sign-in', [AccountPageController::class, 'signIn'])->name('login');
Route::get('register', [AccountPageController::class, 'register'])->name('register');
Route::get('verify', [AccountPageController::class, 'verify'])->name('verify');
Route::get('forgot-password', [AccountPageController::class, 'forgotPassword'])->name('password.request');

// Sign in with GitHub (also connects GitHub to the signed-in account).
Route::middleware('throttle:auth')->group(function () {
    Route::get('auth/github', [GithubSignInController::class, 'redirect'])->name('auth.github');
    Route::get('auth/github/callback', [GithubSignInController::class, 'callback'])->name('auth.github.callback');
});

Route::middleware('auth:web')->group(function () {
    Route::redirect('account', '/account/organizations')->name('account');
    Route::get('account/profile', [AccountPageController::class, 'profile'])->name('account.profile');
    Route::get('account/organizations/{slug?}', [AccountPageController::class, 'organizations'])->name('account.organizations');
    Route::get('account/tokens', [AccountPageController::class, 'tokens'])->name('account.tokens');

    Route::get('studio', [StudioPageController::class, 'index'])->name('studio');
    Route::get('studio/new', [StudioPageController::class, 'create'])->name('studio.create');
    Route::get('studio/insights', [StudioPageController::class, 'insights'])->name('studio.insights');
    Route::get('studio/{slug}', [StudioPageController::class, 'editor'])->name('studio.editor');
    Route::get('studio/{slug}/releases', [StudioPageController::class, 'releases'])->name('studio.releases');
    Route::get('studio/{slug}/releases/{release}', [StudioPageController::class, 'release'])->name('studio.release');
    Route::get('studio/{slug}/review', [StudioPageController::class, 'review'])->name('studio.review');
    Route::get('studio/{slug}/history', [StudioPageController::class, 'history'])->name('studio.history');

    Route::get('review', [ReviewPageController::class, 'queue'])->name('review');
    Route::get('review/publish', [ReviewPageController::class, 'pending'])->name('review.pending');
    Route::get('review/releases/{release}', [ReviewPageController::class, 'release'])->name('review.release');
});

// Everything else is a 404: an Inertia page on the website, the JSON envelope under /v1 (any
// method, so an unknown POST is a 404 rather than a 405).
Route::any('{path}', fn () => abort(404))->where('path', '.*')->fallback();
