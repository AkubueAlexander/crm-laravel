<?php

namespace App\Http\Requests\Accounts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAccountsRequest extends FormRequest
{
    /** Whitelist: the sort param is never interpolated into SQL unchecked. */
    public const SORTABLE = ['name', 'industry', 'created_at'];

    public function authorize(): bool
    {
        return $this->user()?->can('accounts.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}