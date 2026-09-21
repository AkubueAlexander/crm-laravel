<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListLeadsRequest extends FormRequest
{
    public const SORTABLE = ['first_name', 'last_name', 'company', 'status', 'created_at'];

    public const FILTERABLE_STATUSES = ['new', 'contacted', 'qualified', 'disqualified', 'converted'];

    public function authorize(): bool
    {
        return $this->user()?->can('leads.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'status' => ['nullable', Rule::in(self::FILTERABLE_STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}