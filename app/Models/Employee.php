<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 13.1b: a member of the sales organisation. Never mass-assigned: SaveEmployeeAction writes it. */
class Employee extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'hire_date' => 'date',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(HrUnit::class, 'team_id');
    }

    public function territory(): BelongsTo
    {
        return $this->belongsTo(HrUnit::class, 'territory_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
