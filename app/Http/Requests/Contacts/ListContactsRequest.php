<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListContactsRequest extends FormRequest
{
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
            // Tenant-scoped: another tenant's account id must look exactly like a missing one.
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
        ];
    }
}