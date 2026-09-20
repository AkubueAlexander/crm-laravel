<?php

namespace App\Domain\Forecasting\DTOs;

class ForecastResult
{
    /** @param StageForecast[] $byStage */
    public function __construct(
        public readonly float $totalCommit,
        public readonly float $totalBestCase,
        public readonly array $byStage,
    ) {}

    public function toArray(): array
    {
        return [
            'total_commit' => $this->totalCommit,
            'total_best_case' => $this->totalBestCase,
            'by_stage' => array_map(fn (StageForecast $s) => $s->toArray(), $this->byStage),
        ];
    }
}
