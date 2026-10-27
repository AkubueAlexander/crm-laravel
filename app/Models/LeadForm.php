<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** 9.8: a public lead-capture form definition. Not mass-assignable: the Actions forceFill whitelisted values. */
class LeadForm extends Model
{
    use BelongsToTenant;

    protected $table = 'lead_forms';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fields' => 'array',
            'is_active' => 'boolean',
        ];
    }
}