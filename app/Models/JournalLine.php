<?php

namespace App\Models;

use App\Domain\Inventory\Concerns\AppendOnly;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 12.0a: one side of a posting (a debit or a credit). Append-only, like its entry. */
class JournalLine extends Model
{
    use AppendOnly;
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'position' => 'integer',
        'debit' => 'decimal:2',
        'credit' => 'decimal:2',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class, 'ledger_account_id');
    }
}
