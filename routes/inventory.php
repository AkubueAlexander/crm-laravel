<?php

use App\Http\Controllers\Api\Inventory\InventoryItemController;
use App\Http\Controllers\Api\Inventory\InventoryMovementController;
use Illuminate\Support\Facades\Route;

// 11.0b: inventory. Required from routes/api.php INSIDE the authenticated group (auth:sanctum, resolve.tenant).
// {item} is a plain id (no route-model binding): every lookup carries an explicit tenant predicate.
Route::get('inventory/items', [InventoryItemController::class, 'index']);

Route::post('inventory/items', [InventoryItemController::class, 'store'])->middleware('throttle:30,1');

Route::get('inventory/items/{item}', [InventoryItemController::class, 'show'])->whereNumber('item');

Route::put('inventory/items/{item}', [InventoryItemController::class, 'update'])->whereNumber('item')->middleware('throttle:30,1');

// The ledger: read the append-only history, or append one movement (receipt | issue | adjustment).
Route::get('inventory/items/{item}/movements', [InventoryMovementController::class, 'index'])->whereNumber('item');

Route::post('inventory/items/{item}/movements', [InventoryMovementController::class, 'store'])->whereNumber('item')->middleware('throttle:60,1');
