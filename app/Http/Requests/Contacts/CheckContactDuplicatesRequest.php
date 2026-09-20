<?php

namespace App\Http\Requests\Contacts;

use App\Domain\Contacts\Matching\ContactCandidate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Called on blur/debounce while the user is still typing, so the rules only guard
 * shape and size. Half-typed emails must not 422. The service handles low-signal input.
 */
class CheckContactDuplicatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.view') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'exclude_contact_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function candidate(): ContactCandidate
    {
        return new ContactCandidate(
            $this->input('first_name'),
            $this->input('last_name'),
            $this->input('email'),
            $this->input('phone'),
        );
    }

    public function excludeContactId(): ?int
    {
        return $this->integer('exclude_contact_id') ?: null;
    }
}
