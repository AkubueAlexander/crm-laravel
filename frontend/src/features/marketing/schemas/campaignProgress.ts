import { z } from 'zod';

// Pushed on `campaign.progress`: status and counters only (no emails, no names).
// Status stays a plain string here so an unknown future status never breaks the live feed.
export const campaignProgressSchema = z.object({
    id: z.number().int(),
    status: z.string(),
    lock_version: z.number().int(),
    recipients_count: z.number().int().nonnegative(),
    sent_count: z.number().int().nonnegative(),
    failed_count: z.number().int().nonnegative(),
});

export type CampaignProgress = z.infer<typeof campaignProgressSchema>;