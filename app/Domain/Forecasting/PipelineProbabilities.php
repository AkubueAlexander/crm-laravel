<?php

namespace App\Domain\Forecasting;

use App\Models\PipelineStageSetting;

/**
 * 4.3: resolves the effective per-stage probabilities for a tenant —
 * defaults merged with any stored overrides. This is the one piece that
 * touches the DB; ForecastingEngine itself stays DB-free.
 */
class PipelineProbabilities
{
    public const DEFAULTS = [
        'lead' => 0.10,
        'qualified' => 0.30,
        'proposal' => 0.50,
        'negotiation' => 0.70,
        'won' => 1.00,
        // 'lost' deliberately omitted — excluded from forecasting entirely,
        // not just weighted at 0, so it's filtered out before this is used.
    ];

    public function resolve(int $tenantId): array
    {
        $overrides = PipelineStageSetting::query()
            ->where('tenant_id', $tenantId)
            ->pluck('probability', 'stage')
            ->map(fn ($p) => (float) $p)
            ->toArray();

        return array_merge(self::DEFAULTS, $overrides);
    }
}
