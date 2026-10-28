<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Shape check only; UpdateQuoteSettingsAction re-checks permission and the amount format (14.2). */
class UpdateQuoteSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $v = $this->input('approval_threshold');
        if (is_int($v) || is_float($v)) {
            $this->merge(['approval_threshold' => (string) $v]);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Always stated: null (or empty) clears the threshold, so every quote needs a human approver.
            'approval_threshold' => ['present', 'nullable', 'string', 'max:20'],
        ];
    }
}
