<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Marketing\AutomationController;
use Illuminate\Support\Facades\Route;

// 9.6a-2. Required from routes/api.php INSIDE the authenticated /api/v1 group, so it inherits that group's prefix and
// middleware. Literal paths come before {automation}; {automation} is a plain id.
Route::get('automations/catalog', [AutomationController::class, 'catalog'])->name('automations.catalog');
Route::get('automations', [AutomationController::class, 'index'])->name('automations.index');
Route::post('automations', [AutomationController::class, 'store'])->name('automations.store');
Route::get('automations/{automation}', [AutomationController::class, 'show'])->whereNumber('automation')->name('automations.show');
Route::put('automations/{automation}', [AutomationController::class, 'update'])->whereNumber('automation')->name('automations.update');
Route::delete('automations/{automation}', [AutomationController::class, 'destroy'])->whereNumber('automation')->name('automations.destroy');
Route::post('automations/{automation}/transition', [AutomationController::class, 'transition'])->whereNumber('automation')->name('automations.transition');