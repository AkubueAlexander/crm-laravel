<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 9.1: an email campaign. Status changes and counters are never mass-assigned: Actions use forceFill
 * with values they computed. 9.4a: the lifecycle (draft -> scheduled -> sending -> sent/failed/cancelled)
 * is owned by TransitionCampaignAction; the send jobs (9.4c) own the counters.
 */
class Campaign extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'];

    /** A segment or template referenced by a campaign in one of these statuses must not be deleted. */
    public const IN_USE_STATUSES = ['scheduled', 'sending', 'sent', 'failed'];

    protected $fillable = ['name', 'subject'];

    // Postgres column defaults are not on a freshly inserted in-memory model.
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'lock_version' => 0,
        'recipients_count' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
        'opened_count' => 0,
        'clicked_count' => 0,
        'bounced_count' => 0,
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'sent_at' => 'datetime',
        'segment_id' => 'integer',
        'email_template_id' => 'integer',
        'lock_version' => 'integer',
        'recipients_count' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'opened_count' => 'integer',
        'clicked_count' => 'integer',
        'bounced_count' => 'integer',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function segment(): BelongsTo
    {
        return $this->belongsTo(Segment::class);
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }
}