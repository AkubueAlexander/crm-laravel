<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Marketing\Automations\AutomationStepRunner;
use App\Domain\Tenant\TenantContext;
use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 9.6c-2: runs the next step(s) of one automation enrollment. $expectedStep is the enrollment's current_step
 * when the run-due command queued it; a duplicate or retried job finds the row moved on and does nothing.
 * The timeout stays under the redis retry_after (90s).
 */
class RunAutomationEnrollmentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 80;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $enrollmentId,
        public readonly ?int $expectedStep,
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
            AutomationStepRunner::advance($this->tenantId, $this->enrollmentId, $this->expectedStep);
        } finally {
            TenantContext::clear();
        }
    }

    /** All attempts used up: the enrollment is failed instead of being re-queued forever. */
    public function failed(Throwable $e): void
    {
        DB::table('automation_enrollments')
            ->where('tenant_id', $this->tenantId)
            ->where('id', $this->enrollmentId)
            ->where('status', 'active')
            ->update([
                'status' => 'failed',
                'next_run_at' => null,
                'last_error' => mb_substr($e->getMessage(), 0, 500),
                'updated_at' => now(),
            ]);
    }
}