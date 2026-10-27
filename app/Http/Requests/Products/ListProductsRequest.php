<?php

namespace App\Http\Requests\Products;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListProductsRequest extends FormRequest
{
    public const SORTS = ['name', 'sku', 'unit_price', 'created_at'];

    public function authorize(): bool
    {
        return $this->user()?->can('products.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(['active', 'inactive'])],
            'tax_category' => ['sometimes', 'string', Rule::in(Product::TAX_CATEGORIES)],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
