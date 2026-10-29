<?php

use App\Domain\Accounting\Actions\RequestLedgerSyncAction;
use App\Domain\Accounting\Actions\SaveLedgerSyncSettingsAction;
use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Domain\Tenant\TenantContext;
use App\Domain\Users\RoleCatalog;
use App\Jobs\RunLedgerSyncJob;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

function lsyncactUser(Tenant $tenant, string $role = 'manager'): User
{
    TenantContext::set($tenant);
    setPermissionsTeamId($tenant->id);
    RoleCatalog::provision($tenant);

    $user = User::factory()->create(['tenant_id' => $tenant->id]);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user;
}

it('lsyncact saves the provider per tenant and only for accounting managers', function () {
    $b = Tenant::factory()->create();
    lsyncactUser($b);

    $a = Tenant::factory()->create();
    $mgr = lsyncactUser($a);
    $viewer = lsyncactUser($a, 'viewer');
    $sales = lsyncactUser($a, 'sales');
    $save = app(SaveLedgerSyncSettingsAction::class);

    expect(LedgerSyncProviders::current($a->id))->toBe('log');
    expect($save->execute($mgr, 'quickbooks'))->toBe('quickbooks');
    expect(LedgerSyncProviders::current($a->id))->toBe('quickbooks')->and(LedgerSyncProviders::current($b->id))->toBe('log');

    $save->execute($mgr, 'log');
    expect(LedgerSyncProviders::current($a->id))->toBe('log')->and(DB::table('ledger_sync_settings')->where('tenant_id', $a->id)->count())->toBe(1);

    expect(fn () => $save->execute($mgr, 'sage'))->toThrow(ValidationException::class);
    expect(fn () => $save->execute($viewer, 'xero'))->toThrow(AuthorizationException::class);
    expect(fn () => $save->execute($sales, 'xero'))->toThrow(AuthorizationException::class);
});

it('lsyncact queues one sync at a time, per tenant, and closes stale runs', function () {
    Queue::fake();

    $b = Tenant::factory()->create();
    $mb = lsyncactUser($b);
    $theirs = app(RequestLedgerSyncAction::class)->execute($mb); // another tenant's active run must not block tenant a

    $a = Tenant::factory()->create();
    $mgr = lsyncactUser($a);
    $viewer = lsyncactUser($a, 'viewer');
    $sales = lsyncactUser($a, 'sales');
    $request = app(RequestLedgerSyncAction::class);

    app(SaveLedgerSyncSettingsAction::class)->execute($mgr, 'xero');
    $run = $request->execute($mgr);
    expect($run->status)->toBe('queued')->and($run->provider)->toBe('xero')->and($run->requested_by)->toBe($mgr->id)->and($run->tenant_id)->toBe($a->id);
    Queue::assertPushed(RunLedgerSyncJob::class, fn ($j) => $j->tenantId === $a->id && $j->runId === $run->id);

    expect(fn () => $request->execute($mgr))->toThrow(ValidationException::class); // already active
    expect(fn () => $request->execute($viewer))->toThrow(AuthorizationException::class);
    expect(fn () => $request->execute($sales))->toThrow(AuthorizationException::class);
    expect(DB::table('ledger_sync_runs')->where('tenant_id', $a->id)->count())->toBe(1);

    // A dead run (older than 30 minutes) is closed as failed and no longer blocks.
    DB::table('ledger_sync_runs')->where('id', $run->id)->update(['created_at' => now()->subMinutes(31)]);
    $next = $request->execute($mgr);
    expect($next->id)->not->toBe($run->id);
    $old = DB::table('ledger_sync_runs')->where('id', $run->id)->first();
    expect($old->status)->toBe('failed')->and($old->error)->toContain('Timed out')->and($old->finished_at)->not->toBeNull();

    // The other tenant's run was never touched.
    expect(DB::table('ledger_sync_runs')->where('id', $theirs->id)->value('status'))->toBe('queued');
});

it('lsyncact runs end to end through the job and then allows the next sync', function () {
    Queue::fake();
    config(['logging.default' => 'null']);

    $a = Tenant::factory()->create();
    $mgr = lsyncactUser($a);
    $request = app(RequestLedgerSyncAction::class);

    $run = $request->execute($mgr);
    (new RunLedgerSyncJob($a->id, $run->id))->handle();
    TenantContext::set($a);
    setPermissionsTeamId($a->id);

    expect(DB::table('ledger_sync_runs')->where('id', $run->id)->value('status'))->toBe('succeeded');
    expect($request->execute($mgr)->id)->not->toBe($run->id);
});
