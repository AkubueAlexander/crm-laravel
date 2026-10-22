<?php

namespace App\Http\Requests\Activities;

use App\Domain\Tenant\TenantContext;
use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activities.create') ?? false;
    }

    /**
     * The backend boundary (14.2). tenant_id is never accepted; the target must be an
     * alias from the whitelist, and its tenant is verified again in CreateActivityAction.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'activityable_type' => ['required', 'string', Rule::in(array_keys(Activity::TARGETS))],
            'activityable_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', Rule::in(Activity::TYPES)],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'due_at' => ['nullable', 'date'],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('tenant_id', TenantContext::id()),
            ],
        ];
    }
}
