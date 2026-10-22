<?php

namespace App\Http\Requests\Activities;

use App\Domain\Tenant\TenantContext;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activities.update') ?? false;
    }

    /**
     * The target (activityable_*), tenant and completion state are not editable.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::in(Activity::TYPES)],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'body' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'due_at' => ['sometimes', 'nullable', 'date'],
            'owner_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('tenant_id', TenantContext::id()),
            ],
        ];
    }
}
