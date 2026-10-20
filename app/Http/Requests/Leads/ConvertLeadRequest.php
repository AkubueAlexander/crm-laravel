<?php

namespace App\Http\Requests\Leads;

use Illuminate\Foundation\Http\FormRequest;

class ConvertLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leads.convert') ?? false;
    }

    public function rules(): array
    {
        // No body â€” conversion takes no input, same "single-entry-point,
        // no payload" shape as the 3.1 transition endpoint minus the
        // to_stage/lock_version fields, since a Lead has no analogous
        // optimistic-concurrency concern here.
        return [];
    }
}