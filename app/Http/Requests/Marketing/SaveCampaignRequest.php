<?php

namespace App\Http\Requests\Marketing;

use App\Models\Campaign;
use Illuminate\Foundation\Http\FormRequest;

/** Create and update share one rule set: the form always sends the whole campaign. */
class SaveCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('marketing.manage') ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $route = $this->route('campaign');
        $ignoreId = is_numeric($route) ? (int) $route : null;
        $tenantId = (int) $this->user()?->tenant_id;

        return [
            'name' => [
                'required', 'string', 'max:160',
                // Friendly message; the unique index on (tenant_id, lower(name)) is the real guard.
                function (string $attribute, mixed $value, \Closure $fail) use ($ignoreId, $tenantId): void {
                    $exists = Campaign::query()
                        ->where('tenant_id', $tenantId)
                        ->whereRaw('lower(name) = ?', [mb_strtolower((string) $value)])
                        ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();

                    if ($exists) {
                        $fail('A campaign with this name already exists.');
                    }
                },
            ],
            'subject' => ['nullable', 'string', 'max:255'],
        ];
    }
}
