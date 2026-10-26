<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 9.4a: the lifecycle slice of a campaign, returned by the transition endpoint (and as `current` on a 409).
 * Explicit whitelist (7.0). 9.4e folds these fields into CampaignResource for the list and detail views.
 */
class CampaignLifecycleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'lock_version' => (int) $this->lock_version,
            'segment_id' => $this->segment_id,
            'email_template_id' => $this->email_template_id,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'recipients_count' => (int) $this->recipients_count,
            'sent_count' => (int) $this->sent_count,
            'failed_count' => (int) $this->failed_count,
        ];
    }
}