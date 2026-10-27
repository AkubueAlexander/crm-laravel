<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Marketing\LeadFormController;
use Illuminate\Support\Facades\Route;

// 9.8a. Required from routes/api.php INSIDE the authenticated /api/v1 group, so it inherits that group's prefix and
// middleware. The literal path comes before {leadForm}; {leadForm} is a plain id.
Route::get('lead-forms/catalog', [LeadFormController::class, 'catalog'])->name('lead-forms.catalog');
Route::get('lead-forms', [LeadFormController::class, 'index'])->name('lead-forms.index');
Route::post('lead-forms', [LeadFormController::class, 'store'])->name('lead-forms.store');
Route::get('lead-forms/{leadForm}', [LeadFormController::class, 'show'])->whereNumber('leadForm')->name('lead-forms.show');
Route::put('lead-forms/{leadForm}', [LeadFormController::class, 'update'])->whereNumber('leadForm')->name('lead-forms.update');
Route::delete('lead-forms/{leadForm}', [LeadFormController::class, 'destroy'])->whereNumber('leadForm')->name('lead-forms.destroy');