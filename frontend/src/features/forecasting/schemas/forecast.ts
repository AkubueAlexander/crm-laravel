import { z } from 'zod';

// Mirrors ForecastResource / ForecastResult::toArray() — shape drift fails
// loudly here rather than silently rendering undefined in the chart.
export const stageForecastSchema = z.object({
    stage: z.string(),
    deal_count: z.number(),
    total_amount: z.number(),
    weighted_amount: z.number(),
    probability: z.number(),
});

export const forecastResultSchema = z.object({
    total_commit: z.number(),
    total_best_case: z.number(),
    by_stage: z.array(stageForecastSchema),
});

export type StageForecast = z.infer<typeof stageForecastSchema>;
export type ForecastResult = z.infer<typeof forecastResultSchema>;
