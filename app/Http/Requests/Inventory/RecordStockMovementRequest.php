<?php

namespace App\Http\Requests\Inventory;

use App\Domain\Inventory\Actions\RecordStockMovementAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape check only. RecordStockMovementAction re-checks everything under the item row lock (2.4): quantity format and
 * sign, insufficient stock, adjustment reason, inactive items. The client never sends a balance.
 */
class RecordStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('inventory.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $v = $this->input('quantity');
        if (is_int($v) || is_float($v)) {
            $this->merge(['quantity' => (string) $v]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(RecordStockMovementAction::TYPES)],
            'quantity' => ['required', 'string', 'max:20'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
