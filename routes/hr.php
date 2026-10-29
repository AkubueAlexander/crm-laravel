<?php

use App\Http\Controllers\Api\Hr\EmployeeController;
use App\Http\Controllers\Api\Hr\HrUnitController;
use Illuminate\Support\Facades\Route;

// 13.1a/b: HR. Required from routes/api.php INSIDE the authenticated group (auth:sanctum, resolve.tenant).
// Permissions: hr.view to read, hr.manage to change. Ids are plain ints (no route-model binding).

// 13.1b: employees.
Route::get('hr/employees', [EmployeeController::class, 'index']);

Route::post('hr/employees', [EmployeeController::class, 'store'])->middleware('throttle:30,1');

Route::get('hr/employees/{employee}', [EmployeeController::class, 'show'])->whereNumber('employee');

Route::put('hr/employees/{employee}', [EmployeeController::class, 'update'])->whereNumber('employee')->middleware('throttle:30,1');

// 13.1a: teams and territories.
foreach (['team' => 'teams', 'territory' => 'territories'] as $kind => $path) {
    Route::get("hr/{$path}", [HrUnitController::class, 'index'])->defaults('kind', $kind);

    Route::post("hr/{$path}", [HrUnitController::class, 'store'])->defaults('kind', $kind)->middleware('throttle:30,1');

    Route::get("hr/{$path}/{unit}", [HrUnitController::class, 'show'])->defaults('kind', $kind)->whereNumber('unit');

    Route::put("hr/{$path}/{unit}", [HrUnitController::class, 'update'])->defaults('kind', $kind)->whereNumber('unit')->middleware('throttle:30,1');
}
