<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Accounting\Sync\LedgerSyncRunner;
use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 12.2: runs one ledger sync. Single attempt: the run row records the outcome, and the user starts another run if
 * needed. The timeout stays under the redis retry_after (90s); a run killed by it is marked failed in failed().
 */
class RunLedgerSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 80;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $runId,
    ) {
    }

    public function handle(): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        TenantContext::set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            app(LedgerSyncRunner::class)->run($this->tenantId, $this->runId);
        } finally {
            TenantContext::clear();
        }
    }

    /** Timed out or crashed: close the run so it does not block the next one. Finished runs are left alone. */
    public function failed(Throwable $e): void
    {
        DB::table('ledger_sync_runs')
            ->where('tenant_id', $this->tenantId)
            ->where('id', $this->runId)
            ->whereIn('status', ['queued', 'running'])
            ->update([
                'status' => 'failed',
                'error' => mb_substr($e->getMessage(), 0, 500),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
