<?php

use App\Http\Controllers\Api\Documents\DocumentPdfController;
use Illuminate\Support\Facades\Route;


Route::get('quotes/{quote}/pdf', [DocumentPdfController::class, 'quote'])->whereNumber('quote')->middleware('throttle:30,1');

Route::get('invoices/{invoice}/pdf', [DocumentPdfController::class, 'invoice'])->whereNumber('invoice')->middleware('throttle:30,1');
