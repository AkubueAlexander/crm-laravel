<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Tenant;
use App\Domain\Marketing\Actions\TransitionCampaignAction;
use App\Domain\Marketing\Sending\RecipientSnapshot;
use App\Domain\Tenant\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * 9.4c: dispatched (after commit) when a campaign enters 'sending'. Freezes the audience, then hands over to
 * SendCampaignChunkJob. Runs with TenantContext set, because the segment query goes through the contacts
 * global scope, which matches nothing without it.
 *
 * The whole snapshot runs in ONE transaction under a lock on the campaign row: a crash halfway leaves no
 * partial audience behind (a retry starts clean), and two Start jobs for one campaign are serialized.
 * Re-entering 'sending' from 'failed' (retry) finds the frozen audience, puts its failed recipients back to
 * pending and carries on.
 */
class StartCampaignSendJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $timeout = 80;

    public function __construct(public readonly int $tenantId, public readonly int $campaignId)
    {
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(TransitionCampaignAction $transition): void
    {
        $tenant = Tenant::query()->find($this->tenantId);
        if ($tenant === null) {
            return;
        }

        TenantContext::set($tenant);
        setPermissionsTeamId($tenant->id);

        try {
            $started = DB::transaction(function (): bool {
                $campaign = DB::table('campaigns')
                    ->where('tenant_id', $this->tenantId)
                    ->where('id', $this->campaignId)
                    ->lockForUpdate()
                    ->first(['id', 'status']);

                // Cancelled (or already finished) before the worker got here.
                if ($campaign === null || $campaign->status !== 'sending') {
                    return false;
                }

                $result = RecipientSnapshot::take($this->tenantId, $this->campaignId);

                if ($result['already_taken']) {
                    DB::table('campaign_recipients')
                        ->where('tenant_id', $this->tenantId)
                        ->where('campaign_id', $this->campaignId)
                        ->where('status', 'failed')
                        ->update(['status' => 'pending', 'error' => null, 'updated_at' => now()]);
                }

                SendCampaignChunkJob::syncCounters($this->tenantId, $this->campaignId);

                return true;
            });

            if (! $started) {
                return;
            }

            if (SendCampaignChunkJob::pendingCount($this->tenantId, $this->campaignId) > 0) {
                SendCampaignChunkJob::dispatch($this->tenantId, $this->campaignId);
            } else {
                // Empty audience (or everyone suppressed): nothing to send, the campaign is done.
                SendCampaignChunkJob::finalize($transition, $this->tenantId, $this->campaignId);
            }
        } catch (\DomainException) {
            // No segment, or the segment vanished: the campaign cannot run.
            SendCampaignChunkJob::failCampaign($this->tenantId, $this->campaignId);
        } finally {
            TenantContext::clear();
        }
    }

    public function failed(Throwable $e): void
    {
        SendCampaignChunkJob::failCampaign($this->tenantId, $this->campaignId);
    }
}