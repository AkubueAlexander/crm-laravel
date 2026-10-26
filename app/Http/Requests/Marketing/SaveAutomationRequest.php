<?php

namespace App\Http\Requests\Marketing;

use App\Domain\Marketing\Automations\AutomationDsl;
use App\Models\Automation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** Create and update share one rule set: the builder always sends the whole automation. */
class SaveAutomationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $route = $this->route('automation');
        $ignoreId = is_numeric($route) ? (int) $route : null;
        $tenantId = (int) $this->user()?->tenant_id;

        return [
            'name' => [
                'required', 'string', 'max:160',
                // Friendly message; the unique index on (tenant_id, lower(name)) is the real guard.
                function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId, $tenantId): void {
                    $exists = Automation::query()
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(name) = ?', [mb_strtolower(trim((string) $value))])
                        ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();

                    if ($exists) {
                        $fail('An automation with this name already exists.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'trigger_type' => ['required', 'string'],
            'trigger_config' => ['nullable', 'array'],
            // "present", not "required": an empty list gets the DSL's own message ("Add at least one step.").
            'steps' => ['present', 'array'],
        ];
    }

    /** The DSL's per-row errors (steps.2.config.amount, ...) so the builder can show them under the right row. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $errors = AutomationDsl::errors(
                    $this->input('trigger_type'),
                    $this->input('trigger_config'),
                    $this->input('steps'),
                    (int) $this->user()?->tenant_id,
                );

                foreach ($errors as $key => $message) {
                    if (! $validator->errors()->has($key)) {
                        $validator->errors()->add($key, $message);
                    }
                }
            },
        ];
    }
}