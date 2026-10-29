<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 12.0: a chart-of-accounts entry. Never mass-assigned: SaveLedgerAccountAction writes it. */
class LedgerAccount extends Model
{
    use BelongsToTenant;

    public const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** Accounts whose normal balance is a debit; the rest are credit-normal. */
    public const DEBIT_NORMAL = ['asset', 'expense'];

    protected $fillable = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'ledger_account_id');
    }
}
