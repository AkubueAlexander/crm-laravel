<?php

namespace App\Http\Requests\Marketing;

use App\Domain\Marketing\Actions\TransitionAutomationAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in(TransitionAutomationAction::USER_TARGETS)],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}