<?php

namespace App\Http\Resources;

use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Lead */
class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'name' => trim(($this->first_name ?? '').' '.($this->last_name ?? '')),
            'email' => $this->email,
            'phone' => $this->phone,
            'company' => $this->company,
            'source' => $this->source,
            'status' => $this->status,
            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->only(['id', 'name'])),
            // 9.7: computed server-side, read-only. score_factors is rebuilt key by key (jsonb does not keep key order).
            'score' => (int) ($this->lead_score ?? 0),
            'score_factors' => $this->scoreFactors(),
            'score_updated_at' => $this->score_updated_at?->toIso8601String(),
            'converted_at' => $this->converted_at?->toIso8601String(),
            'converted_contact_id' => $this->converted_contact_id,
            'converted_account_id' => $this->converted_account_id,
            'converted_deal_id' => $this->converted_deal_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /** @return list<array{key: string, label: string, points: int}> */
    private function scoreFactors(): array
    {
        $factors = is_array($this->score_factors) ? $this->score_factors : [];

        return array_values(array_map(
            static fn ($f): array => [
                'key' => (string) ($f['key'] ?? ''),
                'label' => (string) ($f['label'] ?? ''),
                'points' => (int) ($f['points'] ?? 0),
            ],
            array_filter($factors, 'is_array'),
        ));
    }
}