<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PipelineStageSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['stage', 'probability'];

    protected $casts = [
        'probability' => 'float',
    ];
}
