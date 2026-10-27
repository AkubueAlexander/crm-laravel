<?php

declare(strict_types=1);

use App\Http\Controllers\PublicForms\LeadFormController;
use Illuminate\Support\Facades\Route;

// 9.8a-2. Registered from bootstrap/app.php (then: closure) with the 'api' group and prefix api/public. No auth, no
// tenant middleware: the unguessable form uuid selects the form and the form selects the tenant. Each route carries
// its own throttle (never stack two throttles on one route: they share a counter). The page people open is /f/{uuid}
// (the standalone SPA entry, 9.8c); these are the JSON endpoints behind it.
Route::get('forms/{uuid}', [LeadFormController::class, 'show'])
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->middleware('throttle:60,1')
    ->name('public.lead-forms.show');

Route::post('forms/{uuid}', [LeadFormController::class, 'store'])
    ->where('uuid', '[0-9a-fA-F-]{36}')
    ->middleware('throttle:10,1')
    ->name('public.lead-forms.submit');