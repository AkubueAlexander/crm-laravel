<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Models\EmailTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

/**
 * Preview is lenient on purpose: an empty or half-written template still renders, and unknown
 * variables come back as warnings instead of a 422.
 */
final class PreviewEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('marketing.view');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'subject' => ['nullable', 'string', 'max:'.EmailTemplate::MAX_SUBJECT],
            'body' => ['nullable', 'string', 'max:'.EmailTemplate::MAX_BODY],
        ];
    }
}