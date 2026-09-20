<?php

namespace App\Http\Requests\Accounts;

/** PATCH semantics: same rules as store, but every field is optional. */
class UpdateAccountRequest extends StoreAccountRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('accounts.update') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return collect(parent::rules())
            ->map(fn (array $rules) => ['sometimes', ...$rules])
            ->all();
    }
}