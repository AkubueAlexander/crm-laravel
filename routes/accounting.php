<?php

use App\Http\Controllers\Api\Accounting\JournalEntryController;
use App\Http\Controllers\Api\Accounting\LedgerAccountController;
use App\Http\Controllers\Api\Accounting\LedgerReportController;
use App\Http\Controllers\Api\Accounting\LedgerSyncController;
use Illuminate\Support\Facades\Route;

// 12.0b: general ledger. Required from routes/api.php INSIDE the authenticated group (auth:sanctum, resolve.tenant).
// Ids are plain ints on purpose (no route-model binding): every lookup carries an explicit tenant predicate.
// Permissions: accounting.view to read (including reports), accounting.manage to change.

// Chart of accounts. Literal paths before {account}.
Route::get('accounting/accounts', [LedgerAccountController::class, 'index']);

Route::post('accounting/accounts/defaults', [LedgerAccountController::class, 'defaults'])->middleware('throttle:10,1');

Route::post('accounting/accounts', [LedgerAccountController::class, 'store'])->middleware('throttle:30,1');

Route::put('accounting/accounts/{account}', [LedgerAccountController::class, 'update'])->whereNumber('account')->middleware('throttle:30,1');

// Journal: append-only. Post a balanced entry, or reverse one (a new entry with swapped lines).
Route::get('accounting/journal-entries', [JournalEntryController::class, 'index']);

Route::post('accounting/journal-entries', [JournalEntryController::class, 'store'])->middleware('throttle:60,1');

Route::get('accounting/journal-entries/{entry}', [JournalEntryController::class, 'show'])->whereNumber('entry');

Route::post('accounting/journal-entries/{entry}/reverse', [JournalEntryController::class, 'reverse'])->whereNumber('entry')->middleware('throttle:30,1');

// Operational reports (read-only aggregates over the journal; NOT the books of record).
Route::get('accounting/reports/trial-balance', [LedgerReportController::class, 'trialBalance'])->middleware('throttle:60,1');

Route::get('accounting/reports/income-statement', [LedgerReportController::class, 'incomeStatement'])->middleware('throttle:60,1');

Route::get('accounting/reports/balance-sheet', [LedgerReportController::class, 'balanceSheet'])->middleware('throttle:60,1');

Route::get('accounting/reports/ledger', [LedgerReportController::class, 'ledger'])->middleware('throttle:60,1');

// 12.2: ledger sync to QuickBooks/Xero (provider choice, "Sync now", run history). Literal paths only.
Route::get('accounting/sync', [LedgerSyncController::class, 'show']);

Route::put('accounting/sync/settings', [LedgerSyncController::class, 'saveSettings'])->middleware('throttle:30,1');

Route::post('accounting/sync/run', [LedgerSyncController::class, 'run'])->middleware('throttle:10,1');

Route::get('accounting/sync/runs', [LedgerSyncController::class, 'runs']);
