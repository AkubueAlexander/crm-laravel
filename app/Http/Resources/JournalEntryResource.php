<?php

namespace App\Http\Resources;

use App\Models\JournalEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A journal entry as the SPA sees it (12.0b). Explicit whitelist: no tenant id. Money is a decimal string.
 * "total" is the entry's total debits (= total credits). "reversed_by_entry_id" is attached by the controller.
 * Lines appear only when loaded (show / store / reverse); the list omits them.
 *
 * @mixin JournalEntry
 */
class JournalEntryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $total = $this->resource->getAttribute('total_debit');
        if ($total === null && $this->relationLoaded('lines')) {
            $total = $this->lines->sum(fn ($l) => (float) $l->debit);
        }

        return [
            'id' => $this->id,
            'entry_seq' => $this->entry_seq,
            'entry_number' => $this->entry_number,
            'entry_date' => $this->entry_date?->format('Y-m-d'),
            'memo' => $this->memo,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'reverses_entry_id' => $this->reverses_entry_id,
            'reversed_by_entry_id' => $this->resource->getAttribute('reversed_by_entry_id'),
            'posted_by' => $this->posted_by,
            'total' => $total === null ? null : number_format((float) $total, 2, '.', ''),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($l) => [
                'id' => $l->id,
                'position' => $l->position,
                'ledger_account_id' => $l->ledger_account_id,
                'account_code' => $l->relationLoaded('account') ? $l->account?->code : null,
                'account_name' => $l->relationLoaded('account') ? $l->account?->name : null,
                'debit' => $l->debit,
                'credit' => $l->credit,
                'description' => $l->description,
            ])->values()->all()),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
