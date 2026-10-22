<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

/**
 * All fields optional: they only shape the Deal created by the conversion.
 * Zod on the frontend is UX; this is the boundary (14.2).
 */
final class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leads.convert') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'deal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'amount' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
