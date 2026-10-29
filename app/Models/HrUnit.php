<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** 13.1a: a sales team or a territory (kind). Never mass-assigned: SaveHrUnitAction writes it. */
class HrUnit extends Model
{
    use BelongsToTenant;

    public const KIND_TEAM = 'team';

    public const KIND_TERRITORY = 'territory';

    public const KINDS = [self::KIND_TEAM, self::KIND_TERRITORY];

    protected $fillable = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
