<?php

declare(strict_types=1);

use App\Http\Controllers\Unsubscribe\UnsubscribeController;
use Illuminate\Support\Facades\Route;

// 9.4c. Registered from bootstrap/app.php (then: closure) with the 'api' + throttle middleware and NO prefix, so the
// links in the emails are /unsubscribe/{token} on the app URL. No session, no CSRF (mail clients POST one-click
// unsubscribes without a token), no authentication: the signed token is the credential.
Route::get('/unsubscribe/{token}', [UnsubscribeController::class, 'show'])
    ->where('token', '[A-Za-z0-9_.-]{10,200}')
    ->name('unsubscribe.show');

Route::post('/unsubscribe/{token}', [UnsubscribeController::class, 'confirm'])
    ->where('token', '[A-Za-z0-9_.-]{10,200}')
    ->name('unsubscribe.confirm');