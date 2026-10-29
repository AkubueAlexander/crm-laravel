<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** 12.2: explicit whitelist of a sync run's fields. */
class LedgerSyncRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'status' => $this->status,
            'requested_by' => $this->requested_by,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'error' => $this->error,
            'accounts_synced' => $this->accounts_synced,
            'entries_synced' => $this->entries_synced,
            'to_entry_id' => $this->to_entry_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
