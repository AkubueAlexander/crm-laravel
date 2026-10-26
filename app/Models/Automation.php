<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 9.6: a drip sequence (trigger -> ordered steps). Status, trigger and lock_version are never mass-assigned:
 * Actions validate with AutomationDsl and forceFill.
 */
class Automation extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    public const STATUSES = ['draft', 'active', 'paused'];

    protected $fillable = ['name', 'description'];

    // Postgres column defaults are not on a freshly inserted in-memory model.
    protected $attributes = [
        'status' => self::STATUS_DRAFT,
        'lock_version' => 0,
    ];

    protected $casts = [
        'trigger_config' => 'array',
        'lock_version' => 'integer',
    ];

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationStep::class)->orderBy('position');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}