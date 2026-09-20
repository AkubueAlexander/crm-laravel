<?php

use App\Http\Controllers\Api\Accounts\AccountController;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Contacts\ContactController;
use App\Http\Controllers\Api\Contacts\ContactMatchSettingsController;
use App\Http\Controllers\Api\Deals\DealsController;
use App\Http\Controllers\Api\Forecasting\ForecastController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // 2.1: unauthenticated - login only needs the CSRF cookie already set.
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login');

    Route::middleware(['auth:sanctum', 'resolve.tenant'])->group(function () {
        Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
            ->name('logout');


        Route::get('/me', CurrentUserController::class)->name('me');

        Route::post('deals/{deal}/transition', [DealsController::class, 'transition']);

        Route::get('deals', [DealsController::class, 'index']);

        Broadcast::routes(['middleware' => []]);

        Route::get('forecast', ForecastController::class);

        Route::post('contacts/check-duplicates', [ContactController::class, 'checkDuplicates'])
            ->middleware('throttle:60,1');
        Route::get('contacts/match-settings', [ContactMatchSettingsController::class, 'show']);
        Route::put('contacts/match-settings', [ContactMatchSettingsController::class, 'update']);
        Route::apiResource('contacts', ContactController::class);

        // 8a.1: Accounts (last-write-wins CRUD, not a state machine).
        Route::apiResource('accounts', AccountController::class);
    });

});