import { z } from 'zod';

// Mirrors ContactMatchSettingsController's JSON shape and
// UpdateContactMatchSettingsRequest::rules() (BETWEEN 1 AND 100).
export const matchSettingsSchema = z.object({
    match_threshold: z.number().int().min(1).max(100),
    default_threshold: z.number().int(),
});

export type MatchSettings = z.infer<typeof matchSettingsSchema>;

export const updateMatchSettingsSchema = z.object({
    match_threshold: z
        .number({ invalid_type_error: 'Enter a number between 1 and 100' })
        .int('Must be a whole number')
        .min(1, 'Must be at least 1')
        .max(100, 'Must be at most 100'),
});

export type UpdateMatchSettingsInput = z.infer<typeof updateMatchSettingsSchema>;