<?php

namespace App\Http\Resources;

use App\Models\Segment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A segment as the SPA sees it (9.2). An explicit whitelist: no tenant id, no creator id. Conditions are rebuilt
 * key by key because jsonb does not preserve key order.
 *
 * @mixin Segment
 */
class SegmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $conditions = [];

        foreach ($this->conditions ?? [] as $condition) {
            $conditions[] = [
                'field' => $condition['field'] ?? null,
                'operator' => $condition['operator'] ?? null,
                'value' => $condition['value'] ?? null,
            ];
        }

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'match_mode' => $this->match_mode,
            'conditions' => $conditions,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
