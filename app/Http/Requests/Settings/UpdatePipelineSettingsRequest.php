<?php

namespace App\Http\Requests\Settings;

use App\Domain\Settings\PipelineRules;
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
        return PipelineRules::all(true);
    }
}