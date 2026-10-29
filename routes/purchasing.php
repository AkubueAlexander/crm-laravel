<?php

use App\Http\Controllers\Api\Purchasing\PurchaseOrderController;
use App\Http\Controllers\Api\Purchasing\SupplierController;
use Illuminate\Support\Facades\Route;

// 11.2b: suppliers and purchase orders. Required from routes/api.php INSIDE the authenticated group (auth:sanctum,
// resolve.tenant). Ids are plain ints on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
// Permissions: inventory.view to read, inventory.manage to change.
Route::get('suppliers', [SupplierController::class, 'index']);

Route::post('suppliers', [SupplierController::class, 'store'])->middleware('throttle:30,1');

Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier');

Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->middleware('throttle:30,1');

Route::get('purchase-orders', [PurchaseOrderController::class, 'index']);

Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware('throttle:30,1');

Route::get('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])->whereNumber('purchaseOrder');

// Edits a DRAFT: needs the current lock_version (409 with the current PO when stale).
Route::put('purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])->whereNumber('purchaseOrder')->middleware('throttle:30,1');

// The single status-transition endpoint (state-machine pattern 3.0): { to_status, lock_version, note? }.
Route::post('purchase-orders/{purchaseOrder}/transition', [PurchaseOrderController::class, 'transition'])->whereNumber('purchaseOrder')->middleware('throttle:30,1');

// Receives goods (writes ledger receipts): { lock_version, receipts: [{ line_id, quantity }], note? }.
Route::post('purchase-orders/{purchaseOrder}/receive', [PurchaseOrderController::class, 'receive'])->whereNumber('purchaseOrder')->middleware('throttle:30,1');
