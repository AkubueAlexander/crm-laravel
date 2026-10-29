<?php

use App\Http\Controllers\Api\Hr\LeaveRequestController;
use Illuminate\Support\Facades\Route;

// 13.2a: required from routes/api.php inside the authenticated group. Literal paths before {id}.
Route::get('hr/leave-requests', [LeaveRequestController::class, 'index']);
Route::get('hr/leave-requests/out', [LeaveRequestController::class, 'out']);
Route::post('hr/leave-requests', [LeaveRequestController::class, 'store']);
Route::get('hr/leave-requests/{id}', [LeaveRequestController::class, 'show'])->whereNumber('id');
Route::put('hr/leave-requests/{id}', [LeaveRequestController::class, 'update'])->whereNumber('id');
Route::post('hr/leave-requests/{id}/transition', [LeaveRequestController::class, 'transition'])->whereNumber('id');
