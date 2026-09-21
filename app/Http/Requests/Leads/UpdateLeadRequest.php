<?php

namespace App\Http\Requests\Leads;

class UpdateLeadRequest extends StoreLeadRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('leads.update') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return collect(parent::rules())
            ->map(fn (array $rules) => ['sometimes', ...$rules])
            ->all();
    }
}