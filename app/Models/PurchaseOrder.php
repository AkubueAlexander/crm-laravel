<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 11.2: a purchase order. Never mass-assigned: the purchasing Actions use forceFill with values they computed.
 * Status moves through TransitionPurchaseOrderAction (draft/ordered/cancelled) and ReceivePurchaseOrderAction.
 */
class PurchaseOrder extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'ordered', 'partially_received', 'received', 'cancelled'];

    protected $fillable = [];

    protected $attributes = [
        'status' => 'draft',
        'lock_version' => 0,
        'currency' => 'USD',
        'subtotal' => 0,
    ];

    protected $casts = [
        'po_seq' => 'integer',
        'lock_version' => 'integer',
        'expected_date' => 'date',
        'subtotal' => 'decimal:2',
        'ordered_at' => 'datetime',
        'received_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('position')->orderBy('id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
