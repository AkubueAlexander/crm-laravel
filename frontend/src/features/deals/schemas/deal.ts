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

// Mirrors DealResource (7.0) — a shape drift here fails loudly via .parse()
// rather than silently rendering undefined in the board.
export const dealSchema = z.object({
    id: z.union([z.string(), z.number()]),
    board_id: z.union([z.string(), z.number()]),
    name: z.string(),
    owner_id: z.union([z.string(), z.number()]).nullable(),
    amount: z.union([z.string(), z.number()]).nullable(),
    expected_close_date: z.string().nullable(),
    stage: dealStageSchema,
    stage_label: z.string(),
    lock_version: z.number(),
    created_at: z.string(),
    updated_at: z.string(),
});

export type Deal = z.infer<typeof dealSchema>;
