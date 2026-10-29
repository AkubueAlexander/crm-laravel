<?php

namespace App\Domain\Accounting\Sync;

use Illuminate\Support\Facades\Log;

/** 12.2: makes no external calls. Logs counts only (no ledger content), so the whole flow can run without credentials. */
class LogLedgerSyncAdapter implements LedgerSyncAdapter
{
    public function pushAccounts(array $accounts): int
    {
        Log::info('ledger sync (log adapter): accounts', ['count' => count($accounts)]);

        return count($accounts);
    }

    public function pushEntries(array $entries): int
    {
        Log::info('ledger sync (log adapter): entries', ['count' => count($entries)]);

        return count($entries);
    }
}
