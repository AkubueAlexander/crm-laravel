<?php

namespace App\Events;

use App\Models\Deal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DealStageChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Deal $deal,
        public readonly ?string $fromStageSlug,
        public readonly string $toStageSlug,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->deal->tenant_id}.board.{$this->deal->board_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'DealStageChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'deal_id' => $this->deal->id,
            'from_stage' => $this->fromStageSlug,
            'to_stage' => $this->toStageSlug,
            'lock_version' => $this->deal->lock_version,
        ];
    }
}
