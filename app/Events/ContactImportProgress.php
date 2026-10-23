<?php

namespace App\Events;

use App\Models\ContactImport;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Pushed on the tenant-scoped private user channel authorized by UserChannel (1.5).
 * Broadcast immediately: the job that raises it is already running on a queue worker.
 * The client treats it as a progress signal and re-reads the import through the API.
 */
final class ContactImportProgress implements ShouldBroadcastNow
{
    use Dispatchable;

    public readonly int $tenantId;

    public readonly int $userId;

    /** @var array<string, int|string> */
    public readonly array $payload;

    public function __construct(ContactImport $import)
    {
        $this->tenantId = (int) $import->tenant_id;
        $this->userId = (int) $import->user_id;
        $this->payload = [
            'id' => (int) $import->id,
            'status' => (string) $import->status,
            'total_rows' => (int) $import->total_rows,
            'processed_rows' => (int) $import->processed_rows,
            'created_rows' => (int) $import->created_rows,
            'duplicate_rows' => (int) $import->duplicate_rows,
            'invalid_rows' => (int) $import->invalid_rows,
        ];
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("tenant.{$this->tenantId}.user.{$this->userId}")];
    }

    public function broadcastAs(): string
    {
        return 'contact-import.progress';
    }

    /** @return array<string, int|string> */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
