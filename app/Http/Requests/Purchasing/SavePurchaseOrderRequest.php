<?php

namespace App\Http\Requests\Purchasing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape check only. SavePurchaseOrderAction re-checks permission, supplier and items (same tenant, active),
 * quantities, costs, totals and the draft-only rule (2.4). The client never sends a total.
 */
class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $lines = $this->input('lines');
        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $i => $line) {
            if (! is_array($line)) {
                continue;
            }
            foreach (['quantity', 'unit_cost'] as $key) {
                if (isset($line[$key]) && (is_int($line[$key]) || is_float($line[$key]))) {
                    $lines[$i][$key] = (string) $line[$key];
                }
            }
        }

        $this->merge(['lines' => $lines]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'max:3'],
            'expected_date' => ['nullable', 'string', 'max:10'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'lock_version' => ['sometimes', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.inventory_item_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'string', 'max:20'],
            'lines.*.unit_cost' => ['required', 'string', 'max:20'],
        ];
    }
}
