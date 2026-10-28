<?php

namespace App\Http\Requests\Invoices;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shape check only: which quote and the payment terms. Everything else (number, status, amounts, lines) comes from
 * the quote on the server. Whether the quote is accepted, owned by the tenant and not yet invoiced is decided by
 * CreateInvoiceFromQuoteAction, which re-checks everything (2.4).
 */
class CreateInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'quote_id' => ['required', 'integer', 'min:1'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ];
    }
}
