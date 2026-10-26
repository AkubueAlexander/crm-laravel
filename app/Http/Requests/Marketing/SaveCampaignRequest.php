<?php

namespace App\Http\Requests\Marketing;

use App\Models\Campaign;
use App\Models\EmailTemplate;
use App\Models\Segment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Create and update share one rule set: the form always sends the whole campaign.
 * segment_id / email_template_id are optional keys: when absent, the stored value is left alone (9.4e).
 */
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
            'segment_id' => ['sometimes', 'nullable', 'integer', $this->ownedRow(Segment::class, $tenantId, 'The selected segment does not exist.')],
            'email_template_id' => ['sometimes', 'nullable', 'integer', $this->ownedRow(EmailTemplate::class, $tenantId, 'The selected template does not exist.')],
        ];
    }

    /** @param class-string $model */
    private function ownedRow(string $model, int $tenantId, string $message): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($model, $tenantId, $message): void {
            if ($value === null) {
                return;
            }

            $table = (new $model())->getTable();
            $query = \Illuminate\Support\Facades\DB::table($table)->where('tenant_id', $tenantId)->where('id', (int) $value);

            if (Schema::hasColumn($table, 'deleted_at')) {
                $query->whereNull('deleted_at');
            }

            if (! $query->exists()) {
                $fail($message);
            }
        };
    }
}