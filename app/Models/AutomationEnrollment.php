<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationEnrollment extends Model
{
    use BelongsToTenant;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    // tenant_id is stamped by BelongsToTenant (1.3), never mass-assigned.
    /** @var list<string> */
    protected $fillable = [
        'automation_id',
        'contact_id',
        'lead_id',
        'email',
        'email_normalized',
        'name',
        'job_title',
        'status',
        'current_step',
        'next_run_at',
        'started_at',
        'completed_at',
        'last_error',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'next_run_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}