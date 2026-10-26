import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

export const CAMPAIGN_STATUSES = ['draft', 'scheduled', 'sending', 'sent', 'failed', 'cancelled'] as const;
export type CampaignStatus = (typeof CAMPAIGN_STATUSES)[number];

// Mirrors CampaignResource (9.1a).
export const campaignSchema = z.object({
    id: z.number(),
    name: z.string(),
    subject: z.string().nullable(),
    status: z.enum(CAMPAIGN_STATUSES),
    editable: z.boolean(),
    scheduled_at: z.string().nullable(),
    sent_at: z.string().nullable(),
    recipients_count: z.number(),
    sent_count: z.number(),
    failed_count: z.number(),
    opened_count: z.number(),
    clicked_count: z.number(),
    bounced_count: z.number(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Campaign = z.infer<typeof campaignSchema>;

export const campaignsPageSchema = z.object({
    data: z.array(campaignSchema),
    meta: paginationMetaSchema,
});

export type CampaignsPage = z.infer<typeof campaignsPageSchema>;

export const CAMPAIGN_SORT_COLUMNS = ['name', 'status', 'created_at', 'scheduled_at', 'sent_at', 'recipients_count'] as const;
export type CampaignSortColumn = (typeof CAMPAIGN_SORT_COLUMNS)[number];

// URL-persisted filter/sort/page state for /marketing (8.7). Every field optional; defaults are dropped.
export type CampaignsSearch = {
    q?: string;
    status?: CampaignStatus;
    sort?: CampaignSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateCampaignsSearch(search: Record<string, unknown>): CampaignsSearch {
    const out: CampaignsSearch = {};

    // The router JSON-parses search values, so "123" can arrive as a number.
    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (CAMPAIGN_STATUSES.includes(search.status as CampaignStatus)) out.status = search.status as CampaignStatus;
    if (CAMPAIGN_SORT_COLUMNS.includes(search.sort as CampaignSortColumn)) out.sort = search.sort as CampaignSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}
