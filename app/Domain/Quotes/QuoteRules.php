<?php

namespace App\Domain\Quotes;

use App\Models\Product;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for quote input rules. Used by the HTTP Form Request (fast,
 * field-keyed 422s at the edge) and by SaveQuoteAction (the real boundary, blueprint 2.4).
 */
final class QuoteRules
{
    /** @return array<string, mixed> */
    public static function all(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'currency' => ['nullable', 'string', 'regex:/^[A-Z]{3}$/'],
            'account_id' => ['nullable', 'integer'],
            'contact_id' => ['nullable', 'integer'],
            'valid_until' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:10000'],

            'lines' => ['present', 'array', 'max:100'],
            'lines.*.product_id' => ['nullable', 'integer'],
            'lines.*.variant_id' => ['nullable', 'integer'],
            'lines.*.description' => ['nullable', 'string', 'max:2000'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
            'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],

            'lines.*.name' => ['required_without:lines.*.product_id', 'nullable', 'string', 'max:160'],
            'lines.*.unit_price' => ['required_without:lines.*.product_id', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'lines.*.tax_category' => ['required_without:lines.*.product_id', 'nullable', 'string', Rule::in(Product::TAX_CATEGORIES)],
        ];
    }
}