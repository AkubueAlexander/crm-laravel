<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * 10.0: a catalog product. Never mass-assigned: SaveProductAction uses forceFill with validated values.
 * Soft-deleted, so reads must keep an explicit tenant predicate (the scope and soft deletes interact, 7.0).
 */
class Product extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const TAX_CATEGORIES = ['standard', 'reduced', 'zero', 'exempt'];

    protected $fillable = [];

    protected $attributes = [
        'tax_category' => 'standard',
        'is_active' => true,
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('sort_order')->orderBy('id');
    }
}
