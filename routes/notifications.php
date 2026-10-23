<?php

use App\Http\Controllers\Api\Notifications\NotificationsController;
use Illuminate\Support\Facades\Route;

// Loaded from bootstrap/app.php (withRouting then:) inside api + auth:sanctum + resolve.tenant, prefix /api/v1.
Route::middleware('throttle:120,1')->group(function () {
    Route::get('notifications', [NotificationsController::class, 'index']);
    Route::post('notifications/read-all', [NotificationsController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationsController::class, 'read']);
});
