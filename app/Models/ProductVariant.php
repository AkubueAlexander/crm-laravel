<?php

namespace App\Models;

use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** 10.0: a sellable variant of a product. unit_price null = inherit the product's price. Written only by SaveProductAction. */
class ProductVariant extends Model
{
    use BelongsToTenant;

    protected $fillable = [];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
