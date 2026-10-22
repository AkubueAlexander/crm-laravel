<?php

namespace App\Http\Resources;

use App\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Activity */
class ActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Explicit whitelist (7.0). tenant_id and soft-delete columns are never exposed.
        return [
            'id' => $this->id,
            'activityable_type' => $this->activityable_type,
            'activityable_id' => $this->activityable_id,
            'type' => $this->type,
            'subject' => $this->subject,
            'body' => $this->body,
            'due_at' => $this->due_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'is_completed' => $this->completed_at !== null,
            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name'])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
