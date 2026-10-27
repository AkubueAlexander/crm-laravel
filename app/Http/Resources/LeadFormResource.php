<?php

namespace App\Http\Resources;

use App\Models\LeadForm;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lead form as the admin SPA sees it. An explicit whitelist: no tenant id, no creator id. The fields list is
 * rebuilt key by key because jsonb does not preserve key order.
 *
 * @mixin LeadForm
 */
class LeadFormResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $fields = is_array($this->fields) ? $this->fields : [];

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'name' => $this->name,
            'is_active' => (bool) $this->is_active,
            'source' => $this->source,
            'success_message' => $this->success_message,
            'fields' => array_values(array_map(
                static fn ($f): array => [
                    'key' => (string) ($f['key'] ?? ''),
                    'label' => (string) ($f['label'] ?? ''),
                    'required' => (bool) ($f['required'] ?? false),
                ],
                array_filter($fields, 'is_array'),
            )),
            // Path only: the SPA joins it with its own origin when it shows the link and the embed snippet.
            'public_path' => '/f/'.$this->uuid,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}