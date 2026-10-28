<?php

use App\Http\Controllers\Api\Documents\DocumentPdfController;
use Illuminate\Support\Facades\Route;

// 10.5: PDF documents. Required from routes/api.php INSIDE the authenticated group (auth:sanctum, resolve.tenant).
Route::get('quotes/{quote}/pdf', [DocumentPdfController::class, 'quote'])->whereNumber('quote')->middleware('throttle:30,1');

Route::get('invoices/{invoice}/pdf', [DocumentPdfController::class, 'invoice'])->whereNumber('invoice')->middleware('throttle:30,1');
