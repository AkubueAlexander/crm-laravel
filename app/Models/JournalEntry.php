<?php

namespace App\Models;

use App\Domain\Inventory\Concerns\AppendOnly;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** 12.0a: one balanced posting. Append-only: written only by PostJournalEntryAction, never edited or deleted. */
class JournalEntry extends Model
{
    use AppendOnly;
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = [];

    protected $casts = [
        'entry_seq' => 'integer',
        'entry_date' => 'date',
        'reverses_entry_id' => 'integer',
        'source_id' => 'integer',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('position')->orderBy('id');
    }
}
