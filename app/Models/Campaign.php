<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 9.1: an email campaign. Status changes and counters are never mass-assigned: Actions use forceFill
 * with values they computed (the send job in 9.4 owns the lifecycle and the counters).
 */
class Campaign extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'cancelled'];

    protected $fillable = ['name', 'subject'];

    // Postgres column defaults are not on a freshly inserted in-memory model.
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'recipients_count' => 0,
        'sent_count' => 0,
        'opened_count' => 0,
        'clicked_count' => 0,
        'bounced_count' => 0,
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'sent_at' => 'datetime',
        'recipients_count' => 'integer',
        'sent_count' => 'integer',
        'opened_count' => 'integer',
        'clicked_count' => 'integer',
        'bounced_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}
