<?php

namespace App\Domain\Forecasting\DTOs;

class StageForecast
{
    public function __construct(
        public readonly string $stage,
        public readonly int $dealCount,
        public readonly float $totalAmount,
        public readonly float $weightedAmount,
        public readonly float $probability,
    ) {}

    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'deal_count' => $this->dealCount,
            'total_amount' => $this->totalAmount,
            'weighted_amount' => $this->weightedAmount,
            'probability' => $this->probability,
        ];
    }
}
