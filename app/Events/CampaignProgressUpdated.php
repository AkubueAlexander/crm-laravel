<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Live campaign status and send counters (9.4d, 6.0 pattern).
 *
 * Payload is counters and status only: no recipient emails, no names, no subject, no tenant id.
 * Broadcast on the tenant-wide private channel `tenant.{tenantId}.campaigns`.
 */
final class CampaignProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $campaignId,
        public readonly string $status,
        public readonly int $lockVersion,
        public readonly int $recipientsCount,
        public readonly int $sentCount,
        public readonly int $failedCount,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.campaigns")];
    }

    public function broadcastAs(): string
    {
        return 'campaign.progress';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->campaignId,
            'status' => $this->status,
            'lock_version' => $this->lockVersion,
            'recipients_count' => $this->recipientsCount,
            'sent_count' => $this->sentCount,
            'failed_count' => $this->failedCount,
        ];
    }
}