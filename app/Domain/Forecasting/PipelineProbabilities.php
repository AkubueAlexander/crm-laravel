<?php

namespace App\Domain\Forecasting;

use App\Models\PipelineStageSetting;


class PipelineProbabilities
{
    public const DEFAULTS = [
        'lead' => 0.10,
        'qualified' => 0.30,
        'proposal' => 0.50,
        'negotiation' => 0.70,
        'won' => 1.00,
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
