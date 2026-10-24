<?php

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

/** Zod on the frontend is UX; this is the boundary (14.2). */
class UpdateTenantBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.manage') ?? false;
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // Strict #rrggbb: the value ends up in a CSS custom property in the shell.
            'primary_color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'remove_logo' => ['sometimes', 'boolean'],
            // No SVG: it can carry script. The action re-encodes whatever passes as a PNG anyway.
            'logo' => [
                'sometimes',
                'file',
                'max:1024',
                'mimes:png,jpg,jpeg,webp',
                'dimensions:max_width=4000,max_height=4000',
            ],
        ];
    }
}
