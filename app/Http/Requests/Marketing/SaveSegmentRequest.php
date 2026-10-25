<?php

namespace App\Http\Requests\Marketing;

use App\Domain\Marketing\Segments\SegmentDsl;
use App\Models\Segment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** Create and update share one rule set: the builder always sends the whole segment. */
class SaveSegmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $route = $this->route('segment');
        $ignoreId = is_numeric($route) ? (int) $route : null;
        $tenantId = (int) $this->user()?->tenant_id;

        return [
            'name' => [
                'required', 'string', 'max:160',
                // Friendly message; the unique index on (tenant_id, lower(name)) is the real guard.
                function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId, $tenantId): void {
                    $exists = Segment::query()
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();

                    if ($exists) {
                        $fail('A segment with this name already exists.');
                    }
                },
            ],
            'description' => ['nullable', 'string', 'max:500'],
            'match_mode' => ['required', 'string', Rule::in(Segment::MATCH_MODES)],
            // "present", not "required": an empty list gets the DSL's own message ("Add at least one condition.").
            'conditions' => ['present', 'array'],
        ];
    }

    /** The DSL's per-row errors (conditions.2.value, ...) so the builder can show them under the right row. */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('conditions')) {
                    return;
                }

                foreach (SegmentDsl::errors($this->input('conditions')) as $key => $message) {
                    $validator->errors()->add($key, $message);
                }
            },
        ];
    }
}
