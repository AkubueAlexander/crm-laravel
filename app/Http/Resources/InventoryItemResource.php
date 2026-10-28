<?php

namespace App\Http\Resources;

use App\Domain\Inventory\StockQuantity;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An inventory item as the SPA sees it (11.0b). Explicit whitelist: no tenant id. Quantities are decimal strings.
 * low_stock: an active item with a reorder level whose on-hand has fallen to or below it.
 *
 * @mixin InventoryItem
 */
class InventoryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $onHand = StockQuantity::fromDecimal($this->on_hand);
        $reorder = StockQuantity::fromDecimal($this->reorder_level);

        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'name' => $this->name,
            'unit' => $this->unit,
            'on_hand' => $this->on_hand,
            'reorder_level' => $this->reorder_level,
            'is_active' => (bool) $this->is_active,
            'low_stock' => (bool) $this->is_active && $reorder > 0 && $onHand <= $reorder,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
