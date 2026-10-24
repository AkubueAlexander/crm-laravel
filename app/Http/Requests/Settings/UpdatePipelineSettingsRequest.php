<?php

namespace App\Http\Requests\Settings;

use App\Domain\Forecasting\PipelineProbabilities;
use Illuminate\Foundation\Http\FormRequest;

/** Zod on the frontend is UX; this is the boundary (14.2). */
class UpdatePipelineSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = ['stages' => ['required', 'array']];

        // Every editable stage is required, so a save always states the whole pipeline.
        foreach (array_keys(PipelineProbabilities::DEFAULTS) as $slug) {
            $rules["stages.{$slug}"] = ['required', 'numeric', 'between:0,1', 'decimal:0,2'];
        }

        return $rules;
    }
}
