<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** 11.0b: query-string shape for the item list. Sort is a whitelist: the column name never comes from the client. */
class ListInventoryItemsRequest extends FormRequest
{
    public const SORT_COLUMNS = [
        'sku' => 'sku',
        'name' => 'name',
        'on_hand' => 'on_hand',
        'reorder_level' => 'reorder_level',
        'created_at' => 'created_at',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('inventory.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'active' => ['nullable', 'boolean'],
            'low_stock' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
