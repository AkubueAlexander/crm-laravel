<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Sync\LedgerSyncProviders;
use App\Jobs\RunLedgerSyncJob;
use App\Models\LedgerSyncRun;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

/**
 * 12.2: "Sync now". Creates a queued run for the tenant's current provider and dispatches the job AFTER the outermost
 * commit. One active run per tenant: a friendly pre-check plus the partial unique index as the race guard. A queued or
 * running run older than 30 minutes is considered dead (worker crash) and is closed as failed first, so it cannot block
 * syncing forever. Needs accounting.manage.
 */
final class RequestLedgerSyncAction
{
    private const STALE_MINUTES = 30;

    public function execute(User $actor): LedgerSyncRun
    {
        $tenantId = (int) $actor->tenant_id;
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);
        Gate::forUser($actor)->authorize('accounting.manage');

        $provider = LedgerSyncProviders::current($tenantId);

        try {
            $run = DB::transaction(function () use ($actor, $tenantId, $provider): LedgerSyncRun {
                LedgerSyncRun::query()
                    ->where('tenant_id', $tenantId)
                    ->whereIn('status', LedgerSyncRun::ACTIVE)
                    ->where('created_at', '<', now()->subMinutes(self::STALE_MINUTES))
                    ->update([
                        'status' => 'failed',
                        'error' => 'Timed out: the sync did not finish and was closed.',
                        'finished_at' => now(),
                    ]);

                $active = LedgerSyncRun::query()->where('tenant_id', $tenantId)->whereIn('status', LedgerSyncRun::ACTIVE)->exists();
                if ($active) {
                    throw ValidationException::withMessages(['sync' => ['A sync is already in progress.']]);
                }

                $run = new LedgerSyncRun();
                $run->forceFill([
                    'tenant_id' => $tenantId,
                    'provider' => $provider,
                    'status' => 'queued',
                    'requested_by' => $actor->id,
                ])->save();

                return $run;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['sync' => ['A sync is already in progress.']]);
        }

        $runId = $run->id;
        DB::afterCommit(function () use ($tenantId, $runId): void {
            try {
                RunLedgerSyncJob::dispatch($tenantId, $runId);
            } catch (Throwable $e) {
                report($e);
                DB::table('ledger_sync_runs')->where('tenant_id', $tenantId)->where('id', $runId)->where('status', 'queued')->update([
                    'status' => 'failed',
                    'error' => 'Could not queue the sync. Please try again.',
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $run;
    }
}
