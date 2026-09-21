<?php

namespace App\Http\Requests\Contacts;

/** PATCH semantics: same rules as store, but every field is optional. */
class UpdateContactRequest extends StoreContactRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.update') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return collect(parent::rules())
            ->map(fn (array $rules) => ['sometimes', ...$rules])
            ->all();
    }
}
