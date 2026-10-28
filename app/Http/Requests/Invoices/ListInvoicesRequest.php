<?php

namespace App\Http\Requests\Invoices;

use App\Models\Invoice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListInvoicesRequest extends FormRequest
{
    /** API sort key => column. "invoice_number" sorts by the numeric sequence, not the string. */
    public const SORT_COLUMNS = [
        'invoice_number' => 'invoice_seq',
        'title' => 'title',
        'status' => 'status',
        'total' => 'total',
        'issue_date' => 'issue_date',
        'due_date' => 'due_date',
        'created_at' => 'created_at',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('invoices.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(Invoice::STATUSES)],
            'sort' => ['sometimes', 'string', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
