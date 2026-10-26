<?php

declare(strict_types=1);

namespace App\Http\Requests\Marketing;

use App\Domain\Marketing\Templates\TemplateVariables;
use App\Models\EmailTemplate;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * UX-level validation. The Create/Update actions validate again and the unique index is the real guard.
 */
final class SaveEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();

        return $user !== null && $user->can('marketing.manage');
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = (int) Auth::user()?->tenant_id;
        $routeId = $this->route('template');
        $ignoreId = $routeId !== null ? (int) $routeId : null;

        return [
            'name' => [
                'required', 'string', 'max:'.EmailTemplate::MAX_NAME,
                function (string $attribute, mixed $value, Closure $fail) use ($tenantId, $ignoreId): void {
                    if (! is_string($value)) {
                        return;
                    }
                    $exists = DB::table('email_templates')
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(name) = lower(?)', [trim($value)])
                        ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();
                    if ($exists) {
                        $fail('A template with this name already exists.');
                    }
                },
            ],
            'subject' => ['required', 'string', 'max:'.EmailTemplate::MAX_SUBJECT],
            'body' => ['required', 'string', 'max:'.EmailTemplate::MAX_BODY],
        ];
    }

    /** @return array<int, Closure> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $data = $validator->getData();
                $subject = $data['subject'] ?? null;
                $body = $data['body'] ?? null;
                if (! is_string($subject) || ! is_string($body)) {
                    return;
                }
                $errors = TemplateVariables::errors($subject, $body);
                foreach (['subject', 'body'] as $key) {
                    if ($validator->errors()->has($key)) {
                        continue;
                    }
                    foreach ($errors[$key] ?? [] as $message) {
                        $validator->errors()->add($key, $message);
                    }
                }
            },
        ];
    }
}