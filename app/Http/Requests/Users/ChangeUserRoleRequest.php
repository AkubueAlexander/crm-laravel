<?php

namespace App\Http\Requests\Users;

use App\Domain\Users\RoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Zod on the frontend is UX; this is the boundary (14.2). */
class ChangeUserRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(RoleCatalog::names())],
        ];
    }
}
