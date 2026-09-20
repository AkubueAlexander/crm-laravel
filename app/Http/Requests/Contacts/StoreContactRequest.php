<?php

namespace App\Http\Requests\Contacts;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The Laravel side of the rules; the Zod schema in 5.C mirrors these.
 * This is the security boundary. Zod is UX only (14.2).
 * account_id is deliberately absent until 8a.0 gives it a tenant-scoped exists rule.
 */
class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.create') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[\d\s+().\-x#]+$/i'],
            'job_title' => ['nullable', 'string', 'max:150'],
        ];
    }
}
