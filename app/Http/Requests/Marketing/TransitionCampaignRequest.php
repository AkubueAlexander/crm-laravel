<?php

namespace App\Http\Requests\Marketing;

use App\Domain\Marketing\Actions\TransitionCampaignAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in(TransitionCampaignAction::USER_TARGETS)],
            'lock_version' => ['required', 'integer', 'min:0'],
            // Required only when scheduling; the Action checks it is in the future.
            'scheduled_at' => ['nullable', 'date', 'required_if:to_status,scheduled'],
        ];
    }
}