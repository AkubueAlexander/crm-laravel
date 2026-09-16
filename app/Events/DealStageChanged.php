<?php

namespace App\Events;

use App\Models\Deal;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * 3.4/6.0: broadcasts on the tenant+board-scoped private channel. The
 * frontend's useEchoChannel hook listens on this exact channel+event name
 * and patches only the affected card via queryClient.setQueryData — this
 * event's broadcastWith() payload is deliberately minimal (ids + new stage),
 * not a full DealResource, so the frontend patch stays cheap.
 */
class DealStageChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly Deal $deal,
        public readonly string $fromStage,
        public readonly string $toStage,
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
            'from_stage' => $this->fromStage,
            'to_stage' => $this->toStage,
            'lock_version' => $this->deal->lock_version,
        ];
    }
}
