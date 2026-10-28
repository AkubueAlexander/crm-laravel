import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';
import { TAX_CATEGORIES } from '@/features/products/schemas/product';

export const QUOTE_STATUSES = ['draft', 'pending_approval', 'approved', 'rejected', 'sent', 'accepted', 'declined', 'expired'] as const;
export type QuoteStatus = (typeof QUOTE_STATUSES)[number];

export const QUOTE_STATUS_LABELS: Record<QuoteStatus, string> = {
    draft: 'Draft',
    pending_approval: 'Pending approval',
    approved: 'Approved',
    rejected: 'Rejected',
    sent: 'Sent',
    accepted: 'Accepted',
    declined: 'Declined',
    expired: 'Expired',
};

// Mirrors QuoteResource lines (10.1b). Money and quantities are decimal strings, never floats.
export const quoteLineSchema = z.object({
    id: z.number(),
    position: z.number(),
    product_id: z.number().nullable(),
    variant_id: z.number().nullable(),
    sku: z.string().nullable(),
    name: z.string(),
    description: z.string().nullable(),
    quantity: z.string(),
    unit_price: z.string(),
    discount_percent: z.string(),
    tax_category: z.enum(TAX_CATEGORIES),
    tax_rate: z.string(),
    line_total: z.string(),
    tax_amount: z.string(),
});

// Mirrors QuoteResource. lines_count is on list rows, lines on show/store/update responses.
export const quoteSchema = z.object({
    id: z.number(),
    quote_seq: z.number(),
    quote_number: z.string(),
    version: z.number(),
    status: z.enum(QUOTE_STATUSES),
    lock_version: z.number(),
    title: z.string(),
    account_id: z.number().nullable(),
    contact_id: z.number().nullable(),
    currency: z.string(),
    valid_until: z.string().nullable(),
    notes: z.string().nullable(),
    terms: z.string().nullable(),
    subtotal: z.string(),
    tax_total: z.string(),
    total: z.string(),
    created_by: z.number().nullable(),
    submitted_at: z.string().nullable().optional(),
    decided_at: z.string().nullable().optional(),
    decision_note: z.string().nullable().optional(),
    sent_at: z.string().nullable().optional(),
    lines_count: z.number().optional(),
    lines: z.array(quoteLineSchema).optional(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Quote = z.infer<typeof quoteSchema>;

// 10.3a mirror (TransitionQuoteAction::TRANSITIONS). The server is the authority; this only decides which buttons show.
export const QUOTE_TRANSITIONS: Record<QuoteStatus, QuoteStatus[]> = {
    draft: ['pending_approval'],
    pending_approval: ['approved', 'rejected', 'draft'],
    approved: ['sent'],
    sent: ['accepted', 'declined', 'expired'],
    rejected: [],
    accepted: [],
    declined: [],
    expired: [],
};

// Moving INTO these needs quotes.approve; everything else needs quotes.manage.
export const QUOTE_APPROVER_TARGETS: QuoteStatus[] = ['approved', 'rejected'];

export const QUOTE_ACTION_LABELS: Record<QuoteStatus, string> = {
    draft: 'Withdraw',
    pending_approval: 'Submit for approval',
    approved: 'Approve',
    rejected: 'Reject',
    sent: 'Mark sent',
    accepted: 'Mark accepted',
    declined: 'Mark declined',
    expired: 'Mark expired',
};
export type QuoteLine = z.infer<typeof quoteLineSchema>;

export const quotesPageSchema = z.object({
    data: z.array(quoteSchema),
    meta: paginationMetaSchema,
});

export type QuotesPage = z.infer<typeof quotesPageSchema>;

export const QUOTE_SORT_COLUMNS = ['quote_number', 'title', 'status', 'total', 'valid_until', 'created_at'] as const;
export type QuoteSortColumn = (typeof QUOTE_SORT_COLUMNS)[number];

// URL-persisted filter/sort/page state for /quotes (8.7). Every field optional; defaults are dropped.
export type QuotesSearch = {
    q?: string;
    status?: QuoteStatus;
    sort?: QuoteSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateQuotesSearch(search: Record<string, unknown>): QuotesSearch {
    const out: QuotesSearch = {};

    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (QUOTE_STATUSES.includes(search.status as QuoteStatus)) out.status = search.status as QuoteStatus;
    if (QUOTE_SORT_COLUMNS.includes(search.sort as QuoteSortColumn)) out.sort = search.sort as QuoteSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}

export function formatMoney(value: string | number, currency: string): string {
    const n = Number(value);
    if (Number.isNaN(n)) return String(value);
    try {
        return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(n);
    } catch {
        return `${n.toFixed(2)} ${currency}`;
    }
}
