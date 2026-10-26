<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RunAutomationEnrollmentJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 9.6c-2: queues one RunAutomationEnrollmentJob per due enrollment of an ACTIVE automation.
 * Rows are claimed with FOR UPDATE SKIP LOCKED and LEASED (next_run_at pushed out) in the same transaction,
 * so two overlapping runs never queue the same enrollment; if a job dies the lease simply expires and the
 * enrollment is picked up again.
 */
final class RunDueAutomationEnrollmentsCommand extends Command
{
    public const BATCH = 200;

    public const MAX_BATCHES = 10;

    public const LEASE_MINUTES = 10;

    protected $signature = 'automations:run-due';

    protected $description = 'Queue the next step of every due automation enrollment.';

    public function handle(): int
    {
        $queued = 0;

        for ($i = 0; $i < self::MAX_BATCHES; $i++) {
            $batch = DB::transaction(function (): array {
                $rows = DB::table('automation_enrollments as e')
                    ->join('automations as a', 'a.id', '=', 'e.automation_id')
                    ->whereColumn('a.tenant_id', 'e.tenant_id')
                    ->where('e.status', 'active')
                    ->where('a.status', 'active')
                    ->whereNotNull('e.next_run_at')
                    ->where('e.next_run_at', '<=', now())
                    ->orderBy('e.next_run_at')
                    ->orderBy('e.id')
                    ->limit(self::BATCH)
                    ->lock('for update of e skip locked')
                    ->get(['e.id', 'e.tenant_id', 'e.current_step'])
                    ->all();

                if ($rows !== []) {
                    DB::table('automation_enrollments')
                        ->whereIn('id', array_map(static fn ($r) => (int) $r->id, $rows))
                        ->update(['next_run_at' => now()->addMinutes(self::LEASE_MINUTES), 'updated_at' => now()]);
                }

                return $rows;
            });

            if ($batch === []) {
                break;
            }

            foreach ($batch as $row) {
                RunAutomationEnrollmentJob::dispatch(
                    (int) $row->tenant_id,
                    (int) $row->id,
                    $row->current_step === null ? null : (int) $row->current_step,
                );
                $queued++;
            }
        }

        $this->info("Queued {$queued} automation step run(s).");

        return self::SUCCESS;
    }
}