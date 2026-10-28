<?php

namespace App\Models;

use App\Domain\Inventory\Concerns\AppendOnly;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 11.0a: one row of the append-only stock ledger. Written only by RecordStockMovementAction; never edited or deleted. */
class InventoryMovement extends Model
{
    use AppendOnly;
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'quantity_delta' => 'decimal:3',
        'balance_after' => 'decimal:3',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
