<?php

namespace App\Http\Resources;

use App\Domain\Deals\Actions\TransitionDealAction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DealResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'board_id' => $this->board_id,
            'name' => $this->name,
            'owner_id' => $this->owner_id,
            'amount' => $this->amount,
            'expected_close_date' => $this->expected_close_date?->toDateString(),
            'stage' => array_search($this->state::class, TransitionDealAction::STATE_MAP) ?: null,
            'stage_label' => $this->state->label(),
            // 9.9: where the deal came from (copied from the lead on conversion; read-only).
            'source' => $this->source,
            'lead_form_id' => $this->lead_form_id,
            'utm_source' => $this->utm_source,
            'utm_medium' => $this->utm_medium,
            'utm_campaign' => $this->utm_campaign,
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
