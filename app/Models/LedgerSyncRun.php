<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** 12.2: one ledger sync attempt. Never mass-assigned: the sync Actions and LedgerSyncRunner write it. */
class LedgerSyncRun extends Model
{
    use BelongsToTenant;

    public const ACTIVE = ['queued', 'running'];

    protected $fillable = [];

    protected $attributes = [
        'status' => 'queued',
        'accounts_synced' => 0,
        'entries_synced' => 0,
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'accounts_synced' => 'integer',
        'entries_synced' => 'integer',
        'to_entry_id' => 'integer',
    ];
}
