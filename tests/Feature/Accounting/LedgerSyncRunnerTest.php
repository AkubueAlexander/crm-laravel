<?php

use App\Domain\Accounting\Actions\PostJournalEntryAction;
use App\Domain\Accounting\Actions\SaveLedgerAccountAction;
use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Domain\Accounting\Sync\LogLedgerSyncAdapter;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Jobs\RunLedgerSyncJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

function lsyncrunUser(Tenant $tenant): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole('manager');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

function lsyncrunAccount(User $actor, string $code, string $type): int
{
    return app(SaveLedgerAccountAction::class)->execute($actor, null, ['code' => $code, 'name' => "Account {$code}", 'type' => $type])->id;
}

function lsyncrunPost(User $actor, int $debitAccount, int $creditAccount, string $amount)
{
    return app(PostJournalEntryAction::class)->execute($actor, '2026-10-29', 'Test', [
        ['account_id' => $debitAccount, 'debit' => $amount, 'credit' => ''],
        ['account_id' => $creditAccount, 'debit' => '', 'credit' => $amount],
    ]);
}

function lsyncrunQueue(int $tenantId, string $provider = 'log'): int
{
    return DB::table('ledger_sync_runs')->insertGetId([
        'tenant_id' => $tenantId,
        'provider' => $provider,
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function lsyncrunExec(Tenant $tenant, int $runId): object
{
    (new RunLedgerSyncJob($tenant->id, $runId))->handle();

    return DB::table('ledger_sync_runs')->where('id', $runId)->first();
}

function lsyncrunRecorder(): LogLedgerSyncAdapter
{
    $recorder = new class extends LogLedgerSyncAdapter
    {
        public array $accounts = [];

        public array $entries = [];

        public function pushAccounts(array $accounts): int
        {
            $this->accounts[] = $accounts;

            return count($accounts);
        }

        public function pushEntries(array $entries): int
        {
            $this->entries[] = $entries;

            return count($entries);
        }
    };
    app()->instance(LogLedgerSyncAdapter::class, $recorder);

    return $recorder;
}

it('lsyncrun pushes the chart and only new entries, per tenant', function () {
    config(['logging.default' => 'null']);
    $recorder = lsyncrunRecorder();

    $b = Tenant::factory()->create();
    $mb = lsyncrunUser($b);
    lsyncrunPost($mb, lsyncrunAccount($mb, '1000', 'asset'), lsyncrunAccount($mb, '4000', 'revenue'), '999.00');

    $a = Tenant::factory()->create();
    $mgr = lsyncrunUser($a);
    $cash = lsyncrunAccount($mgr, '1000', 'asset');
    $rev = lsyncrunAccount($mgr, '4000', 'revenue');
    lsyncrunPost($mgr, $cash, $rev, '100.00');
    $second = lsyncrunPost($mgr, $cash, $rev, '50.00');

    $run1 = lsyncrunExec($a, lsyncrunQueue($a->id));
    expect($run1->status)->toBe('succeeded')
        ->and((int) $run1->accounts_synced)->toBe(2)
        ->and((int) $run1->entries_synced)->toBe(2)
        ->and((int) $run1->to_entry_id)->toBe($second->id)
        ->and($run1->started_at)->not->toBeNull()
        ->and($run1->finished_at)->not->toBeNull()
        ->and($run1->error)->toBeNull();

    // Only this tenant's data was pushed.
    expect(array_column($recorder->accounts[0], 'code'))->toBe(['1000', '4000']);
    expect(count($recorder->entries))->toBe(1)
        ->and(array_column($recorder->entries[0], 'entry_number'))->toBe(['JE-0001', 'JE-0002']);
    $firstLine = $recorder->entries[0][0]['lines'][0];
    expect($firstLine['account_code'])->toBe('1000')->and($firstLine['debit'])->toBe('100.00')->and($firstLine['credit'])->toBe('0.00');

    // The job cleared the tenant context: restore it before touching models again.
    TenantContext::set($a);
    setPermissionsTeamId($a->id);

    // A second run continues after the watermark.
    $third = lsyncrunPost($mgr, $cash, $rev, '25.00');
    $run2 = lsyncrunExec($a, lsyncrunQueue($a->id));
    expect($run2->status)->toBe('succeeded')->and((int) $run2->entries_synced)->toBe(1)->and((int) $run2->to_entry_id)->toBe($third->id);
    expect(array_column($recorder->entries[1], 'entry_number'))->toBe(['JE-0003']);

    // Nothing new: nothing pushed, watermark kept.
    $run3 = lsyncrunExec($a, lsyncrunQueue($a->id));
    expect($run3->status)->toBe('succeeded')->and((int) $run3->entries_synced)->toBe(0)->and((int) $run3->to_entry_id)->toBe($third->id);
    expect(count($recorder->entries))->toBe(2);

    // A duplicate job for a finished run does nothing.
    $pushes = count($recorder->accounts);
    $again = lsyncrunExec($a, (int) $run1->id);
    expect($again->status)->toBe('succeeded')->and((int) $again->entries_synced)->toBe(2)->and(count($recorder->accounts))->toBe($pushes);
});

it('lsyncrun records a clear failure for a provider that is not connected and keeps the watermark', function () {
    config(['logging.default' => 'null']);

    $a = Tenant::factory()->create();
    $mgr = lsyncrunUser($a);
    lsyncrunPost($mgr, lsyncrunAccount($mgr, '1000', 'asset'), lsyncrunAccount($mgr, '4000', 'revenue'), '10.00');

    $run = lsyncrunExec($a, lsyncrunQueue($a->id, 'quickbooks'));

    expect($run->status)->toBe('failed')
        ->and($run->error)->toContain('not connected')
        ->and($run->finished_at)->not->toBeNull()
        ->and((int) $run->accounts_synced)->toBe(0)
        ->and($run->to_entry_id)->toBeNull();

    // A failed run does not block the next one (the one-active-run index only covers queued/running).
    expect(lsyncrunQueue($a->id, 'log'))->toBeGreaterThan(0);
});

it('lsyncrun closes an open run in failed() and leaves finished runs alone', function () {
    $a = Tenant::factory()->create();
    lsyncrunUser($a);

    $open = lsyncrunQueue($a->id);
    (new RunLedgerSyncJob($a->id, $open))->failed(new RuntimeException('boom'));
    $row = DB::table('ledger_sync_runs')->where('id', $open)->first();
    expect($row->status)->toBe('failed')->and($row->error)->toBe('boom')->and($row->finished_at)->not->toBeNull();

    $done = DB::table('ledger_sync_runs')->insertGetId([
        'tenant_id' => $a->id, 'provider' => 'log', 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now(),
    ]);
    (new RunLedgerSyncJob($a->id, $done))->failed(new RuntimeException('late'));
    expect(DB::table('ledger_sync_runs')->where('id', $done)->value('status'))->toBe('succeeded');
});

it('lsyncrun enforces one active run per tenant at the database level', function () {
    $a = Tenant::factory()->create();
    lsyncrunUser($a);
    $b = Tenant::factory()->create();

    lsyncrunQueue($a->id);
    expect(lsyncrunQueue($b->id))->toBeGreaterThan(0); // other tenants are unaffected
    expect(fn () => DB::transaction(fn () => lsyncrunQueue($a->id)))->toThrow(Illuminate\Database\QueryException::class);
});

it('lsyncrun resolves the provider per tenant with a safe default', function () {
    $a = Tenant::factory()->create();
    $b = Tenant::factory()->create();

    expect(LedgerSyncProviders::current($a->id))->toBe('log');

    DB::table('ledger_sync_settings')->insert(['tenant_id' => $a->id, 'provider' => 'xero', 'created_at' => now(), 'updated_at' => now()]);
    expect(LedgerSyncProviders::current($a->id))->toBe('xero')->and(LedgerSyncProviders::current($b->id))->toBe('log');

    DB::table('ledger_sync_settings')->where('tenant_id', $a->id)->update(['provider' => 'zzz']);
    expect(LedgerSyncProviders::current($a->id))->toBe('log');
});
