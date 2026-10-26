<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Marketing\EmailTemplateController;
use Illuminate\Support\Facades\Route;

// 9.3b. Required from routes/api.php INSIDE the authenticated /api/v1 group (next to the segments
// routes), so it inherits that group's prefix and middleware. Static paths come before {template}.
Route::get('email-templates/variables', [EmailTemplateController::class, 'variables'])->name('email-templates.variables');
Route::post('email-templates/preview', [EmailTemplateController::class, 'preview'])->middleware('throttle:60,1')->name('email-templates.preview');
Route::get('email-templates', [EmailTemplateController::class, 'index'])->name('email-templates.index');
Route::post('email-templates', [EmailTemplateController::class, 'store'])->name('email-templates.store');
Route::get('email-templates/{template}', [EmailTemplateController::class, 'show'])->whereNumber('template')->name('email-templates.show');
Route::put('email-templates/{template}', [EmailTemplateController::class, 'update'])->whereNumber('template')->name('email-templates.update');
Route::delete('email-templates/{template}', [EmailTemplateController::class, 'destroy'])->whereNumber('template')->name('email-templates.destroy');