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
            'converted_at' => $this->converted_at?->toIso8601String(),
            'converted_contact_id' => $this->converted_contact_id,
            'converted_account_id' => $this->converted_account_id,
            'converted_deal_id' => $this->converted_deal_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}