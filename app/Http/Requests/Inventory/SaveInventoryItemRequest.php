<?php

namespace App\Http\Requests\Inventory;

use Illuminate\Foundation\Http\FormRequest;

/** Shape check only. SaveInventoryItemAction re-checks permission, formats, uniqueness and SKU immutability (2.4). */
class SaveInventoryItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $v = $this->input('reorder_level');
        if (is_int($v) || is_float($v)) {
            $this->merge(['reorder_level' => (string) $v]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:160'],
            'unit' => ['nullable', 'string', 'max:24'],
            'reorder_level' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
