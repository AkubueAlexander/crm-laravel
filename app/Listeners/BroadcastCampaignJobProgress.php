<?php

namespace App\Listeners;

use App\Domain\Marketing\Sending\CampaignProgressBroadcaster;
use App\Jobs\SendCampaignChunkJob;
use App\Jobs\StartCampaignSendJob;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use ReflectionObject;
use Throwable;

/**
 * Broadcasts campaign progress after every send-related queue job (9.4d).
 *
 * One chunk is 25 recipients, which is the natural throttle: one event per chunk, one after the start
 * job (snapshot taken), and one after the chunk that finalizes (sent/failed). Auto-discovered from
 * app/Listeners by the handle* method type hints; no provider registration.
 *
 * It reads the job's tenant and campaign ids from the serialized payload, so the jobs themselves
 * stay untouched. Anything unexpected is ignored: this listener must never break the queue worker.
 */
final class BroadcastCampaignJobProgress
{
    public function handleProcessed(JobProcessed $event): void
    {
        $this->forward($event->job);
    }

    public function handleFailed(JobFailed $event): void
    {
        $this->forward($event->job);
    }

    private function forward(Job $job): void
    {
        try {
            $command = $job->payload()['data']['command'] ?? null;

            if (! is_string($command) || ! str_contains($command, 'Campaign')) {
                return;
            }

            $object = @unserialize($command, [
                'allowed_classes' => [StartCampaignSendJob::class, SendCampaignChunkJob::class],
            ]);

            if (! $object instanceof StartCampaignSendJob && ! $object instanceof SendCampaignChunkJob) {
                return;
            }

            $reflection = new ReflectionObject($object);

            if (! $reflection->hasProperty('tenantId') || ! $reflection->hasProperty('campaignId')) {
                return;
            }

            $tenantId = (int) $reflection->getProperty('tenantId')->getValue($object);
            $campaignId = (int) $reflection->getProperty('campaignId')->getValue($object);

            if ($tenantId > 0 && $campaignId > 0) {
                CampaignProgressBroadcaster::broadcast($tenantId, $campaignId);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}