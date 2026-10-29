<?php

namespace App\Domain\Accounting\Sync;

use App\Models\LedgerSyncRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 12.2: executes one queued sync run. Pushes the chart of accounts, then every journal entry after the provider's
 * watermark (highest to_entry_id of its last successful runs), in chunks. A run that is not 'queued' is ignored, so a
 * duplicate or retried job does nothing. Failures are recorded on the run (never rethrown): the ledger itself is only
 * ever read here, so a failed sync changes nothing in the books.
 */
final class LedgerSyncRunner
{
    private const CHUNK = 200;

    public function run(int $tenantId, int $runId): void
    {
        $run = LedgerSyncRun::query()->where('tenant_id', $tenantId)->find($runId);
        if ($run === null || $run->status !== 'queued') {
            return;
        }

        $run->forceFill(['status' => 'running', 'started_at' => now()])->save();

        try {
            $adapter = LedgerSyncProviders::adapter($run->provider);

            $accounts = DB::table('ledger_accounts')
                ->where('tenant_id', $tenantId)
                ->orderBy('code')
                ->orderBy('id')
                ->get(['id', 'code', 'name', 'type', 'is_active'])
                ->map(fn ($a) => ['id' => (int) $a->id, 'code' => (string) $a->code, 'name' => (string) $a->name, 'type' => (string) $a->type, 'is_active' => (bool) $a->is_active])
                ->all();
            $byId = [];
            foreach ($accounts as $a) {
                $byId[$a['id']] = $a;
            }

            $accountsSynced = $adapter->pushAccounts($accounts);

            $watermark = (int) LedgerSyncRun::query()
                ->where('tenant_id', $tenantId)
                ->where('provider', $run->provider)
                ->where('status', 'succeeded')
                ->max('to_entry_id');

            $cursor = $watermark;
            $entriesSynced = 0;

            while (true) {
                $entries = DB::table('journal_entries')
                    ->where('tenant_id', $tenantId)
                    ->where('id', '>', $cursor)
                    ->orderBy('id')
                    ->limit(self::CHUNK)
                    ->get();

                if ($entries->isEmpty()) {
                    break;
                }

                $lines = DB::table('journal_lines')
                    ->where('tenant_id', $tenantId)
                    ->whereIn('journal_entry_id', $entries->pluck('id')->all())
                    ->orderBy('journal_entry_id')
                    ->orderBy('position')
                    ->orderBy('id')
                    ->get()
                    ->groupBy('journal_entry_id');

                $batch = [];
                foreach ($entries as $e) {
                    $batch[] = [
                        'id' => (int) $e->id,
                        'entry_number' => (string) ($e->entry_number ?? sprintf('JE-%04d', (int) ($e->entry_seq ?? $e->id))),
                        'entry_date' => substr((string) $e->entry_date, 0, 10),
                        'memo' => $e->memo ?? null,
                        'source_type' => $e->source_type ?? null,
                        'source_id' => $e->source_id ?? null,
                        'reverses_entry_id' => $e->reverses_entry_id ?? null,
                        'lines' => ($lines[$e->id] ?? collect())->map(fn ($l) => [
                            'account_id' => (int) $l->ledger_account_id,
                            'account_code' => $byId[(int) $l->ledger_account_id]['code'] ?? null,
                            'account_name' => $byId[(int) $l->ledger_account_id]['name'] ?? null,
                            'description' => $l->description ?? null,
                            'debit' => (string) $l->debit,
                            'credit' => (string) $l->credit,
                        ])->values()->all(),
                    ];
                }

                $entriesSynced += $adapter->pushEntries($batch);
                $cursor = (int) $entries->last()->id;
            }

            $run->forceFill([
                'status' => 'succeeded',
                'finished_at' => now(),
                'accounts_synced' => $accountsSynced,
                'entries_synced' => $entriesSynced,
                'to_entry_id' => $cursor > 0 ? $cursor : null,
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            report($e);
        }
    }
}
