<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** 11.2: a supplier. Never mass-assigned: SaveSupplierAction writes it. */
class Supplier extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $attributes = [
        'is_active' => true,
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
