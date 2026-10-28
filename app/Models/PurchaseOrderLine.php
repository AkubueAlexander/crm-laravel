<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 11.2: one line of a purchase order. received_quantity is only ever moved by ReceivePurchaseOrderAction. */
class PurchaseOrderLine extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $attributes = [
        'received_quantity' => 0,
        'line_total' => 0,
    ];

    protected $casts = [
        'position' => 'integer',
        'quantity' => 'decimal:3',
        'received_quantity' => 'decimal:3',
        'unit_cost' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
