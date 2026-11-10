<?php

use App\Http\Controllers\Api\Notifications\NotificationsController;
use Illuminate\Support\Facades\Route;


Route::middleware('throttle:120,1')->group(function () {
    Route::get('notifications', [NotificationsController::class, 'index']);
    Route::post('notifications/read-all', [NotificationsController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationsController::class, 'read']);
});
