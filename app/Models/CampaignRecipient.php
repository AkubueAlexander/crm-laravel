<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 9.4b: one row per address a campaign send will go to, snapshotted at send start. Written only by
 * RecipientSnapshot (bulk insert) and the send jobs (9.4c) via forceFill/update; never mass-assigned.
 */
class CampaignRecipient extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const SKIPPED = 'skipped';

    protected $fillable = [];

    protected $attributes = [
        'status' => self::PENDING,
        'attempts' => 0,
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }
}