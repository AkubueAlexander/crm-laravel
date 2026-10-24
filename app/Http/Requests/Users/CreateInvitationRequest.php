<?php

namespace App\Http\Requests\Users;

use App\Domain\Users\RoleCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateInvitationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('users.manage') ?? false;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('email'))) {
            $this->merge(['email' => mb_strtolower(trim($this->input('email')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'role' => ['required', 'string', Rule::in(RoleCatalog::names())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['role.in' => 'Choose one of the available roles.'];
    }
}
