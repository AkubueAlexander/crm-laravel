<?php

use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Deals\DealsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — /api/v1/...
|--------------------------------------------------------------------------
| No web routes beyond Sanctum's CSRF cookie endpoint (registered by the
| sanctum package itself at /sanctum/csrf-cookie — not defined here).
| Versioned from day one per 0.0.
*/

Route::prefix('v1')->group(function () {

    // 2.1: unauthenticated — login only needs the CSRF cookie already set.
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login');

    Route::middleware(['auth:sanctum', 'resolve.tenant'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('logout');

        // 2.1/2.3: the single source-of-truth payload for useCurrentUser().
        Route::get('/me', CurrentUserController::class)->name('me');

        // Feature route groups (3.0 deals, 5.0 contacts, ...) register here in
        // later phases, each behind the same auth:sanctum + resolve.tenant pair
        // so every authenticated route gets tenant context for free.
    });


    Route::post('deals/{deal}/transition', [DealsController::class, 'transition']);
});
