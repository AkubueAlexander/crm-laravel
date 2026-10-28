<?php

namespace App\Http\Requests\Invoices;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape check only. Whether the move is legal and whether a note is required is decided by
 * TransitionInvoiceAction, which re-checks everything (2.4).
 */
class TransitionInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in(Invoice::STATUSES)],
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
