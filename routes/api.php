<?php

use App\Http\Controllers\Api\Accounts\AccountController;
use App\Http\Controllers\Api\Accounts\AccountOptionsController;
use App\Http\Controllers\Api\Activities\ActivityController;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Contacts\ContactController;
use App\Http\Controllers\Api\Contacts\ContactExportController;
use App\Http\Controllers\Api\Contacts\ContactImportController;
use App\Http\Controllers\Api\Contacts\ContactMatchSettingsController;
use App\Http\Controllers\Api\Deals\DealsController;
use App\Http\Controllers\Api\Forecasting\ForecastController;
use App\Http\Controllers\Api\Leads\LeadController;
use App\Http\Controllers\Api\Search\SearchController;
use App\Http\Controllers\Api\Settings\TenantSettingsController;
use App\Http\Controllers\Api\Users\UserOptionsController;
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


        // 8b.3: CSV import. Registered BEFORE the contacts apiResource so "imports" is never read as a {contact} id.
        Route::post('contacts/imports', [ContactImportController::class, 'store'])->middleware('throttle:10,1');

        Route::get('contacts/imports/{import}', [ContactImportController::class, 'show']);

        // 8b.4: CSV/Excel export. Registered BEFORE the contacts apiResource so "exports" is never read as a {contact} id.
        Route::post('contacts/exports', [ContactExportController::class, 'store'])->middleware('throttle:10,1');

        Route::get('contacts/exports/{export}', [ContactExportController::class, 'show']);

        // Signed (relative, so it survives the Vite dev proxy) AND authenticated AND owner-only.
        Route::get('contacts/exports/{export}/download', [ContactExportController::class, 'download'])
            ->middleware('signed:relative')
            ->name('contacts.exports.download');

        Route::apiResource('contacts', ContactController::class);


        Route::get('accounts/options', AccountOptionsController::class);

        Route::apiResource('accounts', AccountController::class);

        // Owner-select source: {id, name} of the actor's own tenant only.
        Route::get('users/options', UserOptionsController::class);

        // 8c.1: tenant settings. settings.manage is checked in the form requests and again in each Action.
        Route::get('settings/pipeline', [TenantSettingsController::class, 'pipeline']);

        Route::put('settings/pipeline', [TenantSettingsController::class, 'updatePipeline']);

        // POST (not PUT): PHP does not parse multipart bodies on PUT, and this one carries a file.
        Route::post('settings/branding', [TenantSettingsController::class, 'updateBranding'])->middleware('throttle:20,1');

        // Any signed-in user of the tenant may load their own tenant's logo; the tenant comes from the session.
        Route::get('tenant/logo', [TenantSettingsController::class, 'logo'])->name('tenant.logo');

        // 8b.1: global search. Tenant scope + per-group *.view permission; debounced client-side.
        Route::get('search', SearchController::class)->middleware('throttle:120,1');


        // 8a.2: lead conversion (Contact + Account + Deal), single entry point.
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert']);

        Route::apiResource('leads', LeadController::class);

        // 8a.3: polymorphic activities. List is filtered by ?activityable_type=&activityable_id=.
        Route::post('activities/{activity}/complete', [ActivityController::class, 'complete']);

        Route::apiResource('activities', ActivityController::class)->except('show');
    });

});
