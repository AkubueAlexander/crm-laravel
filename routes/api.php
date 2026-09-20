<?php

use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Contacts\ContactController;
use App\Http\Controllers\Api\Deals\DealsController;
use App\Http\Controllers\Api\Forecasting\ForecastController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // 2.1: unauthenticated — login only needs the CSRF cookie already set.
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
        Route::apiResource('contacts', ContactController::class);
    });

});
