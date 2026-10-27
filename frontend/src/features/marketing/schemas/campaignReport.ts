import { z } from 'zod';
import { CAMPAIGN_STATUSES } from './campaign';

const rate = z.number().nullable();

// Mirrors CampaignReportResource (9.10). The app serves single resources unwrapped (no "data" envelope).
export const campaignReportSchema = z.object({
    campaign: z.object({
        id: z.number(),
        name: z.string(),
        status: z.enum(CAMPAIGN_STATUSES),
        recipients_count: z.number(),
        started_at: z.string().nullable(),
        completed_at: z.string().nullable(),
        sent_at: z.string().nullable(),
    }),
    totals: z.object({
        recipients: z.number(),
        sent: z.number(),
        failed: z.number(),
        skipped: z.number(),
        pending: z.number(),
        delivered: z.number(),
        opened: z.number(),
        clicked: z.number(),
        bounced: z.number(),
        complained: z.number(),
        unsubscribed: z.number(),
    }),
    rates: z.object({ open: rate, click: rate, bounce: rate, unsubscribe: rate }),
    series: z.array(
        z.object({
            date: z.string(),
            sent: z.number(),
            opened: z.number(),
            clicked: z.number(),
            bounced: z.number(),
        }),
    ),
    attribution: z.object({ leads: z.number(), deals: z.number() }),
});

export type CampaignReport = z.infer<typeof campaignReportSchema>;
