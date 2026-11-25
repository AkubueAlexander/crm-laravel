<?php

namespace App\Domain\Settings;

use App\Domain\Forecasting\PipelineProbabilities;

/**
 * Single source of truth for pipeline-stage probability rules. The Form Request requires every stage
 * (a save always states the whole pipeline); the Action accepts a subset, so it uses $requireAll = false.
 */
final class PipelineRules
{
    /** @return array<string, list<string>> */
    public static function all(bool $requireAll = true): array
    {
        $rules = ['stages' => $requireAll ? ['required', 'array'] : ['present', 'array']];

        foreach (array_keys(PipelineProbabilities::DEFAULTS) as $slug) {
            $rules["stages.{$slug}"] = [$requireAll ? 'required' : 'sometimes', 'numeric', 'between:0,1', 'decimal:0,2'];
        }

        return $rules;
    }
}