<?php

use App\Http\Controllers\Api\Invoices\InvoiceController;
use App\Http\Controllers\Api\Invoices\InvoiceLifecycleController;
use Illuminate\Support\Facades\Route;

// 10.4b: invoices. Required from routes/api.php INSIDE the authenticated group (auth:sanctum, resolve.tenant).
// {invoice} is a plain id (no route-model binding): every lookup carries an explicit tenant predicate.
Route::get('invoices', [InvoiceController::class, 'index']);

// Creates a draft invoice from an accepted quote: { quote_id, payment_terms_days? }.
Route::post('invoices', [InvoiceController::class, 'store'])->middleware('throttle:30,1');

Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->whereNumber('invoice');

// The single status-transition endpoint (state-machine pattern 3.0): { to_status, lock_version, note? }.
Route::post('invoices/{invoice}/transition', [InvoiceLifecycleController::class, 'transition'])->whereNumber('invoice')->middleware('throttle:30,1');
