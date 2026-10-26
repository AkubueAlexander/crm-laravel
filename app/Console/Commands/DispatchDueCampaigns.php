<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Domain\Marketing\Actions\TransitionCampaignAction;
use App\Domain\Marketing\Exceptions\CampaignTransitionException;
use App\Domain\Tenant\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class DispatchDueCampaigns extends Command
{
    protected $signature = 'campaigns:dispatch-due';

    protected $description = 'Start scheduled campaigns whose send time has arrived (9.4c).';

    public function handle(TransitionCampaignAction $transition): int
    {
        $started = 0;
        $reverted = 0;

        // Console process: cross-tenant system work. The tenant is set per campaign below.
        $due = DB::table('campaigns')
            ->where('status', 'scheduled')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->limit(100)
            ->get(['id', 'tenant_id']);

        foreach ($due as $row) {
            $tenant = Tenant::query()->find($row->tenant_id);
            if ($tenant === null) {
                continue;
            }

            TenantContext::set($tenant);
            setPermissionsTeamId($tenant->id);

            try {
                // Goes through the one transition path: lock, allowed-map, audience check, start job after commit.
                $transition->executeSystem((int) $row->tenant_id, (int) $row->id, 'sending');
                $started++;
            } catch (CampaignTransitionException $e) {
                // Cancelled or unscheduled while we were looking: nothing to do.
                if ($e->reason === CampaignTransitionException::INVALID) {
                    continue;
                }

                // A campaign that cannot start (audience gone) goes back to draft instead of failing every minute.
                try {
                    $transition->executeSystem((int) $row->tenant_id, (int) $row->id, 'draft');
                    $reverted++;
                } catch (Throwable $inner) {
                    report($inner);
                }
            } catch (Throwable $e) {
                report($e);
            } finally {
                TenantContext::clear();
            }
        }

        $this->info("Started {$started} due campaign(s), returned {$reverted} to draft.");

        return self::SUCCESS;
    }
}