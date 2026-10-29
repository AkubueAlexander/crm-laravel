<?php

namespace App\Http\Resources;

use App\Domain\Inventory\StockQuantity;
use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A purchase order as the SPA sees it (11.2b). Explicit whitelist: no tenant id. Money and quantities are decimal
 * strings. The list carries lines_count; show/store/transition responses carry lines (with the item's sku, name and
 * unit, and "remaining" = still to receive).
 *
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'po_seq' => $this->po_seq,
            'po_number' => $this->po_number,
            'status' => $this->status,
            'lock_version' => $this->lock_version,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => ['id' => $this->supplier->id, 'name' => $this->supplier->name]),
            'currency' => $this->currency,
            'expected_date' => $this->expected_date?->format('Y-m-d'),
            'notes' => $this->notes,
            'subtotal' => $this->subtotal,
            'ordered_at' => $this->ordered_at?->toIso8601String(),
            'received_at' => $this->received_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'cancel_reason' => $this->cancel_reason,
            'created_by' => $this->created_by,
            'lines_count' => $this->whenCounted('lines'),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'inventory_item_id' => $l->inventory_item_id,
                'sku' => $l->relationLoaded('item') ? $l->item?->sku : null,
                'name' => $l->relationLoaded('item') ? $l->item?->name : null,
                'unit' => $l->relationLoaded('item') ? $l->item?->unit : null,
                'quantity' => $l->quantity,
                'unit_cost' => $l->unit_cost,
                'received_quantity' => $l->received_quantity,
                'remaining' => StockQuantity::format(max(0, StockQuantity::fromDecimal($l->quantity) - StockQuantity::fromDecimal($l->received_quantity))),
                'line_total' => $l->line_total,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
