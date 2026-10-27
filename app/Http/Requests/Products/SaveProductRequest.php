<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create and update share one rule set: the form always sends the whole product including its variants
 * (`variants` must be present: an absent key would otherwise mean "delete every variant"). SKU uniqueness and
 * variant ownership are enforced in SaveProductAction, which re-checks everything here (2.4).
 */
class SaveProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('products.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
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
