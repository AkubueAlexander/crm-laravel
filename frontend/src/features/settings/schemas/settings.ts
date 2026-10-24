import { z } from 'zod';

// Mirrors the 8c.1 API (PipelineSettings, TenantResource). The limits and formats below are UX
// only; UpdatePipelineSettingsRequest and UpdateTenantBrandingRequest are the boundary (14.2).

// ---------------------------------------------------------------- pipeline
// The stages are the 3.0 state machine, so the list is fixed. A server/client mismatch fails the
// Zod parse loudly instead of rendering a half-empty form.
export const STAGE_KEYS = ['lead', 'qualified', 'proposal', 'negotiation', 'won'] as const;
export type StageKey = (typeof STAGE_KEYS)[number];

export const pipelineStageSchema = z.object({
    stage: z.enum(STAGE_KEYS),
    label: z.string(),
    probability: z.number(),
    default_probability: z.number(),
    is_default: z.boolean(),
    commit: z.boolean(),
});

export const pipelineSettingsSchema = z.object({
    stages: z.array(pipelineStageSchema).length(STAGE_KEYS.length),
    commit_threshold: z.number(),
});

export type PipelineStage = z.infer<typeof pipelineStageSchema>;
export type PipelineSettings = z.infer<typeof pipelineSettingsSchema>;

// The form edits whole percents; the API stores 0.00-1.00. They map exactly (1% = 0.01).
const percent = z
    .number({ invalid_type_error: 'Enter a whole number' })
    .int('Use a whole number')
    .min(0, 'Must be 0 or more')
    .max(100, 'Must be 100 or less');

export const pipelineFormSchema = z.object({
    stages: z.object({
        lead: percent,
        qualified: percent,
        proposal: percent,
        negotiation: percent,
        won: percent,
    }),
});

export type PipelineFormInput = z.infer<typeof pipelineFormSchema>;

export function toPercent(probability: number): number {
    return Math.round(probability * 100);
}

export function pipelineFormValues(settings: PipelineSettings): PipelineFormInput {
    const stages = {} as PipelineFormInput['stages'];

    for (const stage of settings.stages) {
        stages[stage.stage] = toPercent(stage.probability);
    }

    return { stages };
}

export function pipelinePayload(values: PipelineFormInput): Record<StageKey, number> {
    const payload = {} as Record<StageKey, number>;

    for (const key of STAGE_KEYS) {
        payload[key] = Math.round(values.stages[key]) / 100;
    }

    return payload;
}

// ---------------------------------------------------------------- branding
export const LOGO_MAX_BYTES = 1024 * 1024;

const HEX_COLOR = /^#[0-9a-f]{6}$/i;

export const brandingFormSchema = z.object({
    // Empty means "no custom accent color".
    primary_color: z.string().regex(/^(#[0-9a-fA-F]{6})?$/, 'Use a hex color like #4f46e5'),
    remove_logo: z.boolean(),
    // A plain File (set from the input's onChange), not a FileList: a registered file input makes
    // RHF compare a FileList against the undefined default, so the form was permanently "dirty".
    logo: z
        .instanceof(File)
        .optional()
        .refine((file) => !file || file.size <= LOGO_MAX_BYTES, {
            message: 'The logo must be 1 MB or smaller',
        })
        .refine((file) => !file || /\.(png|jpe?g|webp)$/i.test(file.name), {
            message: 'Choose a PNG, JPG or WebP image',
        }),
});

export type BrandingFormInput = z.infer<typeof brandingFormSchema>;

export function isHexColor(value: string | undefined | null): value is string {
    return typeof value === 'string' && HEX_COLOR.test(value);
}

// tenant.branding is free-form JSON (auth schema), so every read is validated before use.
export function brandingAccent(branding: Record<string, unknown> | null | undefined): string | undefined {
    const value = branding?.primary_color;
    return isHexColor(typeof value === 'string' ? value : undefined) ? (value as string) : undefined;
}

export function brandingLogo(branding: Record<string, unknown> | null | undefined): string | undefined {
    const value = branding?.logo_url;
    return typeof value === 'string' && value.startsWith('/api/') ? value : undefined;
}
