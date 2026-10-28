<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 10.4: an invoice, created from an accepted quote. Never mass-assigned: CreateInvoiceFromQuoteAction and
 * TransitionInvoiceAction use forceFill with values they computed.
 */
class Invoice extends Model
{
    use BelongsToTenant;

    public const STATUSES = ['draft', 'issued', 'paid', 'void'];

    protected $fillable = [];

    protected $attributes = [
        'status' => 'draft',
        'lock_version' => 0,
        'currency' => 'USD',
        'payment_terms_days' => 30,
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected $casts = [
        'invoice_seq' => 'integer',
        'lock_version' => 'integer',
        'quote_seq' => 'integer',
        'payment_terms_days' => 'integer',
        'issue_date' => 'date',
        'due_date' => 'date',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
        'issued_at' => 'datetime',
        'paid_at' => 'datetime',
        'voided_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position')->orderBy('id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
