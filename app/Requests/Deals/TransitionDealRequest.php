<?php

namespace App\Http\Requests\Deals;

use App\Domain\Deals\Actions\TransitionDealAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * 14.2: the real security boundary — the frontend's Zod schema mirrors this
 * for fast feedback, but this is what's actually enforced.
 */
class TransitionDealRequest extends FormRequest
{
    public function authorize(): bool
    {
        // 2.3: every permission check is duplicated server-side — the
        // frontend's usePermission('deals.transition') hook only controls
        // what's shown, this is what's actually allowed.
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
