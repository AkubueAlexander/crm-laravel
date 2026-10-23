<?php

namespace App\Console\Commands;

use App\Models\Activity;
use Illuminate\Console\Command;

class FlagOverdueActivities extends Command
{
    protected $signature = 'activities:flag-overdue';

    protected $description = 'Flag open activities whose due date has passed (all tenants). Idempotent.';

    public function handle(): int
    {
        $now = now();
        $flagged = 0;

        // Cross-tenant by design: this is system work, not a request. The tenant scope
        // is bypassed explicitly rather than relying on console behaviour. Soft-deleted
        // rows stay excluded by the SoftDeletes scope. Query-builder updates skip model
        // events, so the tenant saving-guard is not involved.
        Activity::withoutTenantScope()
            ->whereNull('completed_at')
            ->whereNull('overdue_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->chunkById(500, function ($rows) use ($now, &$flagged) {
                $flagged += Activity::withoutTenantScope()
                    ->whereIn('id', $rows->modelKeys())
                    ->whereNull('completed_at')
                    ->whereNull('overdue_at')
                    ->update(['overdue_at' => $now]);
            });

        $this->info("Flagged {$flagged} overdue " . ($flagged === 1 ? 'activity' : 'activities') . '.');

        return self::SUCCESS;
    }
}
