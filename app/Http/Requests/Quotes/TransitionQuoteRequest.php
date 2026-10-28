<?php

namespace App\Http\Requests\Quotes;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shape check only. Which permission a given move needs (manage vs approve) and whether the move is legal is
 * decided by TransitionQuoteAction, which re-checks everything (2.4).
 */
class TransitionQuoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && ($user->can('quotes.manage') || $user->can('quotes.approve'));
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_status' => ['required', 'string', Rule::in(Quote::STATUSES)],
            'lock_version' => ['required', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
