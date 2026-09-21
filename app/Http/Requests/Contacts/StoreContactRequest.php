<?php

namespace App\Http\Requests\Contacts;

use App\Domain\Tenant\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The Laravel side of the rules; the Zod schema in 5.C mirrors these.
 * This is the security boundary. Zod is UX only (14.2).
 * account_id (8a.1): tenant-scoped, soft-delete-aware exists rule. Validation exists
 * queries bypass Eloquent global scopes, so the explicit tenant_id filter IS the
 * tenant boundary. null clears the link; on update, omitting it leaves it untouched.
 */
class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contacts.create') ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'first_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email:rfc', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[\d\s+().\-x#]+$/i'],
            'job_title' => ['nullable', 'string', 'max:150'],
            'account_id' => [
                'nullable',
                'integer',
                Rule::exists('accounts', 'id')
                    ->where('tenant_id', TenantContext::id())
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
