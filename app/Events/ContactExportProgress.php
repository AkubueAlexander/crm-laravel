<?php

namespace App\Events;

use App\Models\ContactExport;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed on the tenant-scoped private user channel authorized by UserChannel (1.5).
 * Carries no file location: the client re-reads the export through the API, which is
 * the only place a signed download URL is minted.
 */
final class ContactExportProgress implements ShouldBroadcastNow
{
    use Dispatchable;

    public readonly int $tenantId;

    public readonly int $userId;

    /** @var array<string, int|string> */
    public readonly array $payload;

    public function __construct(ContactExport $export)
    {
        $this->tenantId = (int) $export->tenant_id;
        $this->userId = (int) $export->user_id;
        $this->payload = [
            'id' => (int) $export->id,
            'status' => (string) $export->status,
            'row_count' => (int) $export->row_count,
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'contact-export.progress';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
