import { z } from 'zod';

// Mirrors QuoteSettings (10.3c). approval_threshold is a decimal string or null (null = every quote needs a
// human approver). The format below is UX only; UpdateQuoteSettingsAction is the boundary (14.2).
export const quoteSettingsSchema = z.object({
    approval_threshold: z.string().nullable(),
});

export type QuoteSettings = z.infer<typeof quoteSettingsSchema>;

export const quoteSettingsFormSchema = z.object({
    approval_threshold: z
        .string()
        .trim()
        .refine((v) => v === '' || /^\d{1,12}(\.\d{1,2})?$/.test(v), 'Enter an amount like 500 or 500.00, or leave it empty.'),
});

export type QuoteSettingsFormInput = z.infer<typeof quoteSettingsFormSchema>;

export function quoteSettingsFormValues(settings: QuoteSettings): QuoteSettingsFormInput {
    return { approval_threshold: settings.approval_threshold ?? '' };
}

export function quoteSettingsPayload(values: QuoteSettingsFormInput): { approval_threshold: string | null } {
    const v = values.approval_threshold.trim();
    return { approval_threshold: v === '' ? null : v };
}
