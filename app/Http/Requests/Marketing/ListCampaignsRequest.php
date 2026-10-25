<?php

namespace App\Http\Requests\Marketing;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListCampaignsRequest extends FormRequest
{
    public const SORTS = ['name', 'status', 'created_at', 'scheduled_at', 'sent_at', 'recipients_count'];

    public function authorize(): bool
    {
        return $this->user()?->can('marketing.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(Campaign::STATUSES)],
            'sort' => ['sometimes', 'string', Rule::in(self::SORTS)],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }
}
