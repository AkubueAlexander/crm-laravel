<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * 8c.3: one row of the merged audit feed (a stdClass from the union query). Explicit whitelist.
 */
class AuditEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $details = is_string($this->details) ? json_decode($this->details, true) : $this->details;

        return [
            'id' => $this->source.':'.$this->row_id,
            'source' => $this->source,
            'event' => $this->event,
            'occurred_at' => Carbon::parse($this->occurred_at)->toIso8601String(),
            'actor' => $this->user_id === null ? null : [
                'id' => (int) $this->user_id,
                'name' => $this->user_name ?? 'Unknown user',
            ],
            'subject' => $this->subject_type === null ? null : [
                'type' => $this->subject_type,
                'id' => $this->subject_id === null ? null : (int) $this->subject_id,
                'label' => $this->subject_label,
            ],
            'details' => is_array($details) && $details !== [] ? $details : new \stdClass(),
        ];
    }
}
