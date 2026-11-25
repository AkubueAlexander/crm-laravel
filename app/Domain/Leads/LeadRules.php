<?php

namespace App\Domain\Leads;

use Illuminate\Validation\Rule;

/**
 * Single source of truth for lead input rules. Used by the Form Requests (fast, field-keyed 422s)
 * and by CreateLeadAction / UpdateLeadAction (the real boundary, blueprint 2.4).
 * "converted" is never assignable here: only ConvertLeadAction sets it.
 */
final class LeadRules
{
    public const ASSIGNABLE_STATUSES = ['new', 'contacted', 'qualified', 'disqualified'];

    /** @return array<string, list<mixed>> */
    public static function store(int $tenantId): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50', 'regex:/^[\d\s+().\-x#]+$/i'],
            'company' => ['nullable', 'string', 'max:255'],
            'source' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(self::ASSIGNABLE_STATUSES)],
            'owner_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
            ],
        ];
    }

    /** PATCH semantics: same rules as store, every field optional. @return array<string, list<mixed>> */
    public static function update(int $tenantId): array
    {
        return collect(self::store($tenantId))
            ->map(fn (array $rules) => ['sometimes', ...$rules])
            ->all();
    }
}