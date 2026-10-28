<?php

use App\Http\Controllers\Api\Accounts\AccountController;
use App\Http\Controllers\Api\Accounts\AccountOptionsController;
use App\Http\Controllers\Api\Activities\ActivityController;
use App\Http\Controllers\Api\Audit\AuditLogController;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\Contacts\ContactController;
use App\Http\Controllers\Api\Contacts\ContactExportController;
use App\Http\Controllers\Api\Contacts\ContactImportController;
use App\Http\Controllers\Api\Contacts\ContactMatchSettingsController;
use App\Http\Controllers\Api\Deals\DealsController;
use App\Http\Controllers\Api\Forecasting\ForecastController;
use App\Http\Controllers\Api\Leads\LeadController;
use App\Http\Controllers\Api\Marketing\CampaignController;
use App\Http\Controllers\Api\Marketing\SegmentController;
use App\Http\Controllers\Api\Search\SearchController;
use App\Http\Controllers\Api\Settings\TenantSettingsController;
use App\Http\Controllers\Api\Users\PublicInvitationController;
use App\Http\Controllers\Api\Users\UserInvitationController;
use App\Http\Controllers\Api\Users\UserManagementController;
use App\Http\Controllers\Api\Users\UserOptionsController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

    // 2.1: unauthenticated - login only needs the CSRF cookie already set.
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login');

    // 8c.2c: public invitation endpoints. The token is the credential, so they are throttled hard, and
    // every failure (unknown, used, expired, revoked) is the same 410: nothing can be learned by guessing.
    Route::get('/invitations/{token}', [PublicInvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->middleware('throttle:30,1');

    Route::post('/invitations/{token}/accept', [PublicInvitationController::class, 'accept'])
        ->where('token', '[A-Za-z0-9]{48}')
        ->middleware('throttle:10,1');

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

        // 8c.2: user management. users.manage is checked in the form requests and again in each Action.
        // {user} is a plain id on purpose (no route-model binding): User has no tenant scope, so every
        // lookup is an explicit tenant_id query inside the Action.
        Route::get('users', [UserManagementController::class, 'index']);

        Route::get('users/roles', [UserManagementController::class, 'roles']);

        // 8c.2c: invitations, administrator side. Same {id} convention as users: no route-model binding.
        Route::get('users/invitations', [UserInvitationController::class, 'index']);

        Route::post('users/invitations', [UserInvitationController::class, 'store'])->middleware('throttle:20,1');

        Route::post('users/invitations/{invitation}/resend', [UserInvitationController::class, 'resend'])
            ->whereNumber('invitation')
            ->middleware('throttle:20,1');

        Route::delete('users/invitations/{invitation}', [UserInvitationController::class, 'destroy'])->whereNumber('invitation');

        Route::patch('users/{user}/role', [UserManagementController::class, 'changeRole'])->whereNumber('user');

        Route::post('users/{user}/deactivate', [UserManagementController::class, 'deactivate'])->whereNumber('user');

        Route::post('users/{user}/reactivate', [UserManagementController::class, 'reactivate'])->whereNumber('user');

        // 8c.3: audit feed (deal stage changes + administrative events). audit.view is checked in the form request.
        // Registered with its own literal paths; nothing here takes an {id}.
        Route::get('audit-log/actors', [AuditLogController::class, 'actors']);

        Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('throttle:60,1');

        // 8c.1: tenant settings. settings.manage is checked in the form requests and again in each Action.
        Route::get('settings/pipeline', [TenantSettingsController::class, 'pipeline']);

        Route::put('settings/pipeline', [TenantSettingsController::class, 'updatePipeline']);

        // 10.3c: quote settings (auto-approval threshold). settings.manage is checked in the request and again in the Action.
        Route::get('settings/quotes', [\App\Http\Controllers\Api\Settings\QuoteSettingsController::class, 'show']);

        Route::put('settings/quotes', [\App\Http\Controllers\Api\Settings\QuoteSettingsController::class, 'update'])->middleware('throttle:20,1');

        // POST (not PUT): PHP does not parse multipart bodies on PUT, and this one carries a file.
        Route::post('settings/branding', [TenantSettingsController::class, 'updateBranding'])->middleware('throttle:20,1');

        // Any signed-in user of the tenant may load their own tenant's logo; the tenant comes from the session.
        Route::get('tenant/logo', [TenantSettingsController::class, 'logo'])->name('tenant.logo');

        // 9.1: campaigns. {campaign} is a plain id (no route-model binding): every lookup carries an explicit tenant predicate.
        Route::get('campaigns', [CampaignController::class, 'index']);

        Route::post('campaigns', [CampaignController::class, 'store']);

        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');

        Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign');

        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->whereNumber('campaign');

        // 9.2: segments. Literal paths are registered before {segment}; {segment} is a plain id (no route-model binding).
        Route::get('segments/fields', [SegmentController::class, 'fields']);

        // Evaluates a definition against the tenant's contacts, so it is throttled.
        Route::post('segments/preview', [SegmentController::class, 'preview'])->middleware('throttle:60,1');

        Route::get('segments', [SegmentController::class, 'index']);

        Route::post('segments', [SegmentController::class, 'store']);

        Route::get('segments/{segment}', [SegmentController::class, 'show'])->whereNumber('segment');

        Route::put('segments/{segment}', [SegmentController::class, 'update'])->whereNumber('segment');

        Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])->whereNumber('segment');
        // 9.3b: email templates (routes/email_templates.php)
        require __DIR__ . '/email_templates.php';
        require __DIR__ . '/campaign_lifecycle.php';
        // 10.4b: invoices (routes/invoices.php)
        require __DIR__ . '/invoices.php';
        // 11.0b: inventory (routes/inventory.php)
        require __DIR__ . '/inventory.php';
        // 10.5: PDF documents (routes/documents.php)
        require __DIR__ . '/documents.php';
        // 10.0b: product catalog. {product} is a plain id (no route-model binding): every lookup carries an explicit tenant predicate.
        Route::get('products', [\App\Http\Controllers\Api\Products\ProductController::class, 'index']);

        Route::post('products', [\App\Http\Controllers\Api\Products\ProductController::class, 'store']);

        Route::get('products/{product}', [\App\Http\Controllers\Api\Products\ProductController::class, 'show'])->whereNumber('product');

        Route::put('products/{product}', [\App\Http\Controllers\Api\Products\ProductController::class, 'update'])->whereNumber('product');

        Route::delete('products/{product}', [\App\Http\Controllers\Api\Products\ProductController::class, 'destroy'])->whereNumber('product');

        // 10.1b: quotes. {quote} is a plain id (no route-model binding): every lookup carries an explicit tenant predicate.
        Route::get('quotes', [\App\Http\Controllers\Api\Quotes\QuoteController::class, 'index']);

        Route::post('quotes', [\App\Http\Controllers\Api\Quotes\QuoteController::class, 'store']);

        Route::get('quotes/{quote}', [\App\Http\Controllers\Api\Quotes\QuoteController::class, 'show'])->whereNumber('quote');

        Route::put('quotes/{quote}', [\App\Http\Controllers\Api\Quotes\QuoteController::class, 'update'])->whereNumber('quote');

        Route::delete('quotes/{quote}', [\App\Http\Controllers\Api\Quotes\QuoteController::class, 'destroy'])->whereNumber('quote');

        // 10.2: versions. Revise copies a quote that left draft into a new draft version (same number, version + 1).
        Route::get('quotes/{quote}/versions', [\App\Http\Controllers\Api\Quotes\QuoteController::class, 'versions'])->whereNumber('quote');

        Route::post('quotes/{quote}/revise', [\App\Http\Controllers\Api\Quotes\QuoteController::class, 'revise'])->whereNumber('quote')->middleware('throttle:30,1');

        // 10.3a: the single status-transition endpoint (state-machine pattern 3.0): { to_status, lock_version, note? }.
        Route::post('quotes/{quote}/transition', [\App\Http\Controllers\Api\Quotes\QuoteLifecycleController::class, 'transition'])->whereNumber('quote')->middleware('throttle:30,1');

        // 9.10: read-only campaign report (totals, rates, daily series, utm attribution).
        Route::get('campaigns/{campaign}/report', [\App\Http\Controllers\Api\Marketing\CampaignReportController::class, 'show'])
            ->whereNumber('campaign')
            ->middleware('throttle:60,1');
        // 9.6a-2: automations (routes/automations.php)
        require __DIR__ . '/automations.php';
        // 9.8a: lead capture forms, admin side (routes/lead_forms.php)
        require __DIR__ . '/lead_forms.php';

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
