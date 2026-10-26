<?php

use App\Http\Controllers\Engagement\EngagementController;
use Illuminate\Support\Facades\Route;

// 9.5: registered from bootstrap/app.php (outside the authenticated group, no tenant middleware, throttled there).
// Tokens are [A-Za-z0-9_-] plus one dot.
Route::get('e/o/{token}', [EngagementController::class, 'open'])->where('token', '[A-Za-z0-9_.-]+');

Route::get('e/c/{token}', [EngagementController::class, 'click'])->where('token', '[A-Za-z0-9_.-]+');

Route::post('webhooks/email-events', [EngagementController::class, 'events']);