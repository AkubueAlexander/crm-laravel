<?php

namespace App\Domain\Products;

use App\Models\Product;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for product input rules. Used by the HTTP Form Request (fast,
 * field-keyed 422s at the edge) and by SaveProductAction (the real boundary, blueprint 2.4).
 */
final class ProductRules
{
    /** @return array<string, mixed> */
    public static function all(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'unit_price' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'tax_category' => ['required', 'string', Rule::in(Product::TAX_CATEGORIES)],
            'is_active' => ['sometimes', 'boolean'],
            'variants' => ['present', 'array', 'max:50'],
            'variants.*.id' => ['nullable', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:64'],
            'variants.*.name' => ['required', 'string', 'max:160'],
            'variants.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
        ];
    }
}