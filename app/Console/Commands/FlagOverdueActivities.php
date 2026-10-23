<?php

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\User;
use App\Notifications\ActivityOverdueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class FlagOverdueActivities extends Command
{
    /**
     * Rows overdue for longer than this are still flagged but not notified, so the
     * first run after deploy does not flood owners with a historical backlog.
     */
    public const NOTIFY_WINDOW_DAYS = 7;

    protected $signature = 'activities:flag-overdue';

    protected $description = 'Flag open activities whose due date has passed (all tenants) and notify owners. Idempotent.';

    public function handle(): int
    {
        $now = now();
        $flagged = 0;
        $notified = 0;

        // Cross-tenant by design: this is system work, not a request. The tenant scope
        // is bypassed explicitly rather than relying on console behaviour. Soft-deleted
        // rows stay excluded by the SoftDeletes scope. Query-builder updates skip model
        // events, so the tenant saving-guard is not involved.
        Activity::withoutTenantScope()
            ->whereNull('completed_at')
            ->whereNull('overdue_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->chunkById(500, function ($rows) use ($now, &$flagged, &$notified) {
                // Claim the rows under a lock so exactly the rows WE flag are the rows we
                // notify about: a concurrent run, complete, or reopen cannot cause a
                // duplicate or a notification for a row that was not flagged.
                $claimed = DB::transaction(function () use ($rows, $now) {
                    $locked = Activity::withoutTenantScope()
                        ->whereIn('id', $rows->modelKeys())
                        ->whereNull('completed_at')
                        ->whereNull('overdue_at')
                        ->lockForUpdate()
                        ->get();

                    if ($locked->isNotEmpty()) {
                        Activity::withoutTenantScope()
                            ->whereIn('id', $locked->modelKeys())
                            ->update(['overdue_at' => $now]);
                    }

                    return $locked;
                });

                $flagged += $claimed->count();
                $notified += $this->notifyOwners($claimed);
            });

        $this->info("Flagged {$flagged} overdue " . ($flagged === 1 ? 'activity' : 'activities') . '.');

        if ($this->getOutput()->isVerbose()) {
            $this->line("Notified {$notified} owner(s).");
        }

        return self::SUCCESS;
    }

    /** @param Collection<int, Activity> $activities */
    private function notifyOwners(Collection $activities): int
    {
        $ownerIds = $activities->pluck('owner_id')->filter()->unique()->values();

        if ($ownerIds->isEmpty()) {
            return 0;
        }

        $owners = User::query()->whereIn('id', $ownerIds)->get()->keyBy('id');
        $cutoff = now()->subDays(self::NOTIFY_WINDOW_DAYS);
        $sent = 0;

        foreach ($activities as $activity) {
            if ($activity->owner_id === null || $activity->due_at === null || $activity->due_at->lt($cutoff)) {
                continue;
            }

            $owner = $owners->get($activity->owner_id);

            // Never notify across tenants, even if a row's owner_id points elsewhere.
            if ($owner === null || (int) $owner->tenant_id !== (int) $activity->tenant_id) {
                continue;
            }

            try {
                $owner->notify(new ActivityOverdueNotification($activity));
                $sent++;
            } catch (Throwable $e) {
                // A broadcast outage must not stop flagging or other owners' notifications.
                report($e);
            }
        }

        return $sent;
    }
}
