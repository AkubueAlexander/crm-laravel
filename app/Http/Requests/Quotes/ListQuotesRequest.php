<?php

namespace App\Http\Requests\Quotes;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListQuotesRequest extends FormRequest
{
    /** API sort key => column. "quote_number" sorts by the numeric sequence, not the string. */
    public const SORT_COLUMNS = [
        'quote_number' => 'quote_seq',
        'title' => 'title',
        'status' => 'status',
        'total' => 'total',
        'valid_until' => 'valid_until',
        'created_at' => 'created_at',
    ];

    public function authorize(): bool
    {
        return $this->user()?->can('quotes.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(Quote::STATUSES)],
            'sort' => ['sometimes', 'string', Rule::in(array_keys(self::SORT_COLUMNS))],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
