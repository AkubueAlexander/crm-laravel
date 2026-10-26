<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 9.6: one ordered step of an automation. Written only by SaveAutomationAction. */
class AutomationStep extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $casts = [
        'config' => 'array',
        'position' => 'integer',
    ];

    public function automation(): BelongsTo
    {
        return $this->belongsTo(Automation::class);
    }
}