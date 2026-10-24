<?php

namespace App\Http\Requests\Users;

use App\Domain\Users\RoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Zod on the frontend is UX; this is the boundary (14.2). */
class ListUsersRequest extends FormRequest
{
    /** Whitelisted: this value reaches ORDER BY. */
    public const SORTABLE = ['name', 'email', 'created_at'];

    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['active', 'deactivated'])],
            'role' => ['nullable', 'string', Rule::in(RoleCatalog::names())],
            'sort' => ['nullable', 'string', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', 'string', Rule::in(['asc', 'desc'])],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
