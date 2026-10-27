import { z } from 'zod';

export const dealStageSchema = z.enum(['lead', 'qualified', 'proposal', 'negotiation', 'won', 'lost']);
export type DealStage = z.infer<typeof dealStageSchema>;

export const DEAL_STAGES: { value: DealStage; label: string }[] = [
    { value: 'lead', label: 'Lead' },
    { value: 'qualified', label: 'Qualified' },
    { value: 'proposal', label: 'Proposal' },
    { value: 'negotiation', label: 'Negotiation' },
    { value: 'won', label: 'Won' },
    { value: 'lost', label: 'Lost' },
];


export const dealSchema = z.object({
    id: z.union([z.string(), z.number()]),
    board_id: z.union([z.string(), z.number()]),
    name: z.string(),
    owner_id: z.union([z.string(), z.number()]).nullable(),
    amount: z.union([z.string(), z.number()]).nullable(),
    expected_close_date: z.string().nullable(),
    stage: dealStageSchema,
    stage_label: z.string(),
    // 9.9: attribution copied from the lead on conversion, read-only.
    source: z.string().nullable().optional(),
    lead_form_id: z.number().nullable().optional(),
    utm_source: z.string().nullable().optional(),
    utm_medium: z.string().nullable().optional(),
    utm_campaign: z.string().nullable().optional(),
    lock_version: z.number(),
    created_at: z.string(),
    updated_at: z.string(),
});

export type Deal = z.infer<typeof dealSchema>;
