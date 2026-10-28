<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 10.1: one version of a quote. Never mass-assigned: SaveQuoteAction uses forceFill with values it computed
 * (totals, number, status). 10.3 adds the status workflow; until then everything is a draft.
 */
class Quote extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUSES = ['draft', 'pending_approval', 'approved', 'rejected', 'sent', 'accepted', 'declined', 'expired'];

    protected $fillable = [];

    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'version' => 1,
        'lock_version' => 0,
        'currency' => 'USD',
        'subtotal' => 0,
        'tax_total' => 0,
        'total' => 0,
    ];

    protected $casts = [
        'quote_seq' => 'integer',
        'version' => 'integer',
        'lock_version' => 'integer',
        'valid_until' => 'date',
        'subtotal' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'total' => 'decimal:2',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(QuoteLine::class)->orderBy('position')->orderBy('id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
