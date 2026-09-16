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
            'lock_version' => $this->lock_version,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
