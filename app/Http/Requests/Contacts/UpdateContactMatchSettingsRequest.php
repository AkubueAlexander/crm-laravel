<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

/** Mirrors the DB-level CHECK (match_threshold BETWEEN 1 AND 100) constraint. */
class UpdateContactMatchSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.update') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'match_threshold' => ['required', 'integer', 'between:1,100'],
        ];
    }
}