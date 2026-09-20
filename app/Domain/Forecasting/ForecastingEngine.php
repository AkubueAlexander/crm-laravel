<?php

namespace App\Domain\Forecasting;

use App\Domain\Forecasting\DTOs\ForecastResult;
use App\Domain\Forecasting\DTOs\StageForecast;

/**
 * 4.0: plain PHP, no framework dependency, fully Pest-testable without a DB —
 * takes deal data + probabilities as arrays, returns a DTO. Any DB/Eloquent
 * concern (fetching deals, resolving per-tenant probability overrides) lives
 * outside this class, in the controller + PipelineProbabilities.
 *
 * "Commit" = deals in a stage whose probability meets/exceeds this threshold,
 * summed at full amount (high-confidence pipeline). "Best case" = every
 * open deal's amount weighted by its stage's probability.
 */
class ForecastingEngine
{
    private const COMMIT_THRESHOLD = 0.7;

    /**
     * @param array<int, array{stage: string, amount: float}> $deals
     * @param array<string, float> $probabilities stage slug => 0.0-1.0
     */
    public function calculate(array $deals, array $probabilities): ForecastResult
    {
        $totalCommit = 0.0;
        $totalBestCase = 0.0;
        $byStage = [];

        $grouped = [];
        foreach ($deals as $deal) {
            $grouped[$deal['stage']][] = $deal['amount'];
        }

        foreach ($grouped as $stage => $amounts) {
            $probability = $probabilities[$stage] ?? 0.0;
            $totalAmount = array_sum($amounts);
            $weightedAmount = $totalAmount * $probability;

            $totalBestCase += $weightedAmount;

            if ($probability >= self::COMMIT_THRESHOLD) {
                $totalCommit += $totalAmount;
            }

            $byStage[] = new StageForecast(
                stage: $stage,
                dealCount: count($amounts),
                totalAmount: $totalAmount,
                weightedAmount: $weightedAmount,
                probability: $probability,
            );
        }

        return new ForecastResult($totalCommit, $totalBestCase, $byStage);
    }
}
