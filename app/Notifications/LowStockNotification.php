<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

/**
 * 11.3: sent to users who can manage inventory when a stock movement takes an active item from above its reorder
 * level to at or below it (one send per crossing, see RecordStockMovementAction). Database + broadcast, like
 * ActivityOverdueNotification. The payload always carries tenant_id so every read path can filter by tenant.
 */
class LowStockNotification extends Notification
{
    public const KIND = 'low_stock';

    public function __construct(
        private readonly int $tenantId,
        private readonly int $itemId,
        private readonly string $sku,
        private readonly string $name,
        private readonly string $unit,
        private readonly string $onHand,
        private readonly string $reorderLevel,
    ) {
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => self::KIND,
            'tenant_id' => $this->tenantId,
            'item_id' => $this->itemId,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'on_hand' => $this->onHand,
            'reorder_level' => $this->reorderLevel,
        ];
    }

    /** Tiny payload, broadcast synchronously: the client only uses the event as an "invalidate" signal. */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toArray($notifiable)))->onConnection('sync');
    }
}
