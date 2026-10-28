<?php

namespace App\Http\Requests\Invoices;

use App\Domain\Invoices\Actions\RecordPaymentAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 10.6b: shape check only. RecordPaymentAction re-checks everything under the invoice row lock (2.4):
 * amount format and sign, status, overpayment, future dates.
 */
class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('invoices.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $amount = $this->input('amount');
        if (is_int($amount) || is_float($amount)) {
            $this->merge(['amount' => (string) $amount]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'string', 'max:20'],
            'method' => ['required', 'string', Rule::in(RecordPaymentAction::METHODS)],
            'paid_on' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
