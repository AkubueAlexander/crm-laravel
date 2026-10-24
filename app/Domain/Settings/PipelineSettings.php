<?php

namespace App\Domain\Settings;

use App\Domain\Forecasting\ForecastingEngine;
use App\Domain\Forecasting\PipelineProbabilities;

/**
 * 8c.1: the editable pipeline settings of one tenant, in the shape the settings screen reads.
 * The stage list comes from PipelineProbabilities::DEFAULTS (one source of truth with the
 * forecast); stages themselves are the 3.0 state machine and are not tenant-editable.
 */
final class PipelineSettings
{
    public function __construct(private readonly PipelineProbabilities $probabilities)
    {
    }

    /**
     * @return array{stages: list<array{stage: string, label: string, probability: float, default_probability: float, is_default: bool, commit: bool}>, commit_threshold: float}
     */
    public function forTenant(int $tenantId): array
    {
        $resolved = $this->probabilities->resolve($tenantId);
        $stages = [];

        foreach (PipelineProbabilities::DEFAULTS as $slug => $default) {
            $probability = round((float) ($resolved[$slug] ?? $default), 2);

            $stages[] = [
                'stage' => $slug,
                'label' => ucfirst($slug),
                'probability' => $probability,
                'default_probability' => $default,
                'is_default' => abs($probability - $default) < 0.0001,
                // Same rule as ForecastingEngine: at or above the threshold counts toward Commit.
                'commit' => $probability >= ForecastingEngine::COMMIT_THRESHOLD,
            ];
        }

        return [
            'stages' => $stages,
            'commit_threshold' => ForecastingEngine::COMMIT_THRESHOLD,
        ];
    }
}
