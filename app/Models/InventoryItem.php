<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 11.0: a stockable item. Never mass-assigned: SaveInventoryItemAction writes the descriptive fields and
 * RecordStockMovementAction is the only writer of on_hand.
 */
class InventoryItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $attributes = [
        'on_hand' => 0,
        'reorder_level' => 0,
        'is_active' => true,
    ];

    protected $casts = [
        'on_hand' => 'decimal:3',
        'reorder_level' => 'decimal:3',
        'is_active' => 'boolean',
    ];

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class)->orderBy('id');
    }
}
