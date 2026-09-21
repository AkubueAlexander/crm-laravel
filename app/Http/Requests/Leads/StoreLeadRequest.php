<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLeadRequest extends FormRequest
{
    public const ASSIGNABLE_STATUSES = ['new', 'contacted', 'qualified', 'disqualified'];

    public function authorize(): bool
    {
        return $this->user()?->can('leads.create') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[\d\s+().\-x#]+$/i'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::ASSIGNABLE_STATUSES)],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('tenant_id', $this->user()?->tenant_id),
            ],
        ];
    }
}