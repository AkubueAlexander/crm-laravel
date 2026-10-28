<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 10.6: one payment against an invoice. Never mass-assigned: written only by the payment Actions and TransitionInvoiceAction. */
class Payment extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_on' => 'date',
        'voided_at' => 'datetime',
    ];

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
