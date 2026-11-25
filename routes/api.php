<?php

use App\Http\Controllers\Api\Accounts\AccountController;
use App\Http\Controllers\Api\Accounts\AccountOptionsController;
use App\Http\Controllers\Api\Activities\ActivityController;
use App\Http\Controllers\Api\Audit\AuditLogController;
use App\Http\Controllers\Api\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Api\Auth\CurrentUserController;
use App\Http\Controllers\Api\ClientErrorController;
use App\Http\Controllers\Api\Contacts\ContactController;
use App\Http\Controllers\Api\Contacts\ContactExportController;
use App\Http\Controllers\Api\Contacts\ContactImportController;
use App\Http\Controllers\Api\Contacts\ContactMatchSettingsController;
use App\Http\Controllers\Api\Deals\DealsController;
use App\Http\Controllers\Api\Forecasting\ForecastController;
use App\Http\Controllers\Api\Leads\LeadController;
use App\Http\Controllers\Api\Marketing\AutomationEnrollmentController;
use App\Http\Controllers\Api\Marketing\CampaignController;
use App\Http\Controllers\Api\Marketing\CampaignReportController;
use App\Http\Controllers\Api\Marketing\SegmentController;
use App\Http\Controllers\Api\Products\ProductController;
use App\Http\Controllers\Api\Quotes\QuoteController;
use App\Http\Controllers\Api\Quotes\QuoteLifecycleController;
use App\Http\Controllers\Api\Search\SearchController;
use App\Http\Controllers\Api\Settings\TenantSettingsController;
use App\Http\Controllers\Api\Users\PublicInvitationController;
use App\Http\Controllers\Api\Users\UserInvitationController;
use App\Http\Controllers\Api\Users\UserManagementController;
use App\Http\Controllers\Api\Users\UserOptionsController;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {


    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('login');


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

        Broadcast::routes(['middleware' => ['throttle:120,1']]);

        Route::get('forecast', ForecastController::class);

        Route::post('contacts/check-duplicates', [ContactController::class, 'checkDuplicates'])
            ->middleware('throttle:60,1');


        Route::get('contacts/match-settings', [ContactMatchSettingsController::class, 'show']);

        Route::put('contacts/match-settings', [ContactMatchSettingsController::class, 'update']);



        Route::post('contacts/imports', [ContactImportController::class, 'store'])->middleware('throttle:10,1');

        Route::get('contacts/imports/{import}', [ContactImportController::class, 'show']);


        Route::post('contacts/exports', [ContactExportController::class, 'store'])->middleware('throttle:10,1');

        Route::get('contacts/exports/{export}', [ContactExportController::class, 'show']);

        // Signed (relative, so it survives the Vite dev proxy) AND authenticated AND owner-only.
        Route::get('contacts/exports/{export}/download', [ContactExportController::class, 'download'])
            ->middleware('signed:relative')
            ->name('contacts.exports.download');

        Route::apiResource('contacts', ContactController::class);


        Route::get('accounts/options', AccountOptionsController::class);

        Route::apiResource('accounts', AccountController::class);


        Route::get('users/options', UserOptionsController::class);


        Route::get('users', [UserManagementController::class, 'index']);

        Route::get('users/roles', [UserManagementController::class, 'roles']);

        //invitations, administrator side. Same {id} convention as users: no route-model binding.
        Route::get('users/invitations', [UserInvitationController::class, 'index']);

        Route::post('users/invitations', [UserInvitationController::class, 'store'])->middleware('throttle:20,1');

        Route::post('users/invitations/{invitation}/resend', [UserInvitationController::class, 'resend'])
            ->whereNumber('invitation')
            ->middleware('throttle:20,1');

        Route::delete('users/invitations/{invitation}', [UserInvitationController::class, 'destroy'])->whereNumber('invitation');

        Route::patch('users/{user}/role', [UserManagementController::class, 'changeRole'])->whereNumber('user');

        Route::post('users/{user}/deactivate', [UserManagementController::class, 'deactivate'])->whereNumber('user');

        Route::post('users/{user}/reactivate', [UserManagementController::class, 'reactivate'])->whereNumber('user');


        Route::get('audit-log/actors', [AuditLogController::class, 'actors']);

        Route::get('audit-log', [AuditLogController::class, 'index'])->middleware('throttle:60,1');


        Route::get('settings/pipeline', [TenantSettingsController::class, 'pipeline']);

        Route::put('settings/pipeline', [TenantSettingsController::class, 'updatePipeline']);


        Route::get('settings/quotes', [\App\Http\Controllers\Api\Settings\QuoteSettingsController::class, 'show']);

        Route::put('settings/quotes', [\App\Http\Controllers\Api\Settings\QuoteSettingsController::class, 'update'])->middleware('throttle:20,1');


        Route::post('settings/branding', [TenantSettingsController::class, 'updateBranding'])->middleware('throttle:20,1');


        Route::get('tenant/logo', [TenantSettingsController::class, 'logo'])->name('tenant.logo');


        Route::get('campaigns', [CampaignController::class, 'index']);

        Route::post('campaigns', [CampaignController::class, 'store']);

        Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->whereNumber('campaign');

        Route::put('campaigns/{campaign}', [CampaignController::class, 'update'])->whereNumber('campaign');

        Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->whereNumber('campaign');


        Route::get('segments/fields', [SegmentController::class, 'fields']);

        // Evaluates a definition against the tenant's contacts, so it is throttled.
        Route::post('segments/preview', [SegmentController::class, 'preview'])->middleware('throttle:60,1');

        Route::get('segments', [SegmentController::class, 'index']);

        Route::post('segments', [SegmentController::class, 'store']);

        Route::get('segments/{segment}', [SegmentController::class, 'show'])->whereNumber('segment');

        Route::put('segments/{segment}', [SegmentController::class, 'update'])->whereNumber('segment');

        Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])->whereNumber('segment');

        require __DIR__ . '/email_templates.php';
        require __DIR__ . '/campaign_lifecycle.php';

        require __DIR__ . '/invoices.php';

        require __DIR__ . '/inventory.php';

        require __DIR__ . '/purchasing.php';

        require __DIR__ . '/accounting.php';

        require __DIR__ . '/hr.php';
        require __DIR__ . '/leave.php';

        require __DIR__ . '/documents.php';

        Route::get('products', [ProductController::class, 'index']);

        Route::post('products', [ProductController::class, 'store']);

        Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product');

        Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product');

        Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');


        Route::get('quotes', [QuoteController::class, 'index']);

        Route::post('quotes', [QuoteController::class, 'store']);

        Route::get('quotes/{quote}', [QuoteController::class, 'show'])->whereNumber('quote');

        Route::put('quotes/{quote}', [QuoteController::class, 'update'])->whereNumber('quote');

        Route::delete('quotes/{quote}', [QuoteController::class, 'destroy'])->whereNumber('quote');


        Route::get('quotes/{quote}/versions', [QuoteController::class, 'versions'])->whereNumber('quote');

        Route::post('quotes/{quote}/revise', [QuoteController::class, 'revise'])->whereNumber('quote')->middleware('throttle:30,1');


        Route::post('quotes/{quote}/transition', [QuoteLifecycleController::class, 'transition'])->whereNumber('quote')->middleware('throttle:30,1');


        Route::get('campaigns/{campaign}/report', [CampaignReportController::class, 'show'])
            ->whereNumber('campaign')
            ->middleware('throttle:60,1');

        require __DIR__ . '/automations.php';

        require __DIR__ . '/lead_forms.php';

        require __DIR__ . '/documents.php';

        require __DIR__ . '/notifications.php';

        Route::post('automations/{automation}/enroll', [AutomationEnrollmentController::class, 'store'])
            ->whereNumber('automation');

        Route::post('client-errors', [ClientErrorController::class, 'store'])
            ->middleware('throttle:20,1');


        Route::get('search', SearchController::class)->middleware('throttle:120,1');



        Route::post('leads/{lead}/convert', [LeadController::class, 'convert']);

        Route::apiResource('leads', LeadController::class);


        Route::post('activities/{activity}/complete', [ActivityController::class, 'complete']);

        Route::apiResource('activities', ActivityController::class)->except('show');
    });

});

Route::prefix('public')
    ->group(base_path('routes/lead_forms_public.php'));
