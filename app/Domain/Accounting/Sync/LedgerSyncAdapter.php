<?php

namespace App\Domain\Accounting\Sync;

/**
 * 12.2: the seam between our operational ledger and an external books-of-record system (QuickBooks, Xero, ...).
 * Provider-agnostic on purpose: adapters receive plain arrays and return how many items they accepted. They must
 * be idempotent per entry id (a run can be retried) and throw on failure; the runner records the error.
 */
interface LedgerSyncAdapter
{
    /**
     * @param  list<array{id: int, code: string, name: string, type: string, is_active: bool}>  $accounts
     * @return int accounts accepted
     */
    public function pushAccounts(array $accounts): int;

    /**
     * @param  list<array<string, mixed>>  $entries  each: id, entry_number, entry_date, memo, source_type, source_id, reverses_entry_id, lines[]
     * @return int entries accepted
     */
    public function pushEntries(array $entries): int;
}
