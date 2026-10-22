<?php

namespace App\Http\Requests\Activities;

use App\Models\Activity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListActivitiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('activities.view') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'activityable_type' => ['required', 'string', Rule::in(array_keys(Activity::TARGETS))],
            'activityable_id' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::in(['open', 'completed'])],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }
}
