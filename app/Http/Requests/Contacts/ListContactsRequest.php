<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListContactsRequest extends FormRequest
{
    /** Whitelist: the sort param is never interpolated into SQL unchecked. */
    public const SORTABLE = ['last_name', 'first_name', 'email', 'created_at'];

    public function authorize(): bool
    {
        return $this->user()?->can('contacts.view') ?? false;
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
