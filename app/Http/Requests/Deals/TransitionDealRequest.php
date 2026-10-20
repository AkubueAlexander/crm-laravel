<?php

namespace App\Http\Requests\Deals;

use App\Domain\Deals\Actions\TransitionDealAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;


class TransitionDealRequest extends FormRequest
{
    public function authorize(): bool
    {

        return $this->user()?->can('deals.transition') ?? false;
    }

    public function rules(): array
    {
        return [
            'to_stage' => ['required', 'string', Rule::in(array_keys(TransitionDealAction::STATE_MAP))],
            'lock_version' => ['required', 'integer', 'min:0'],
        ];
    }
}
