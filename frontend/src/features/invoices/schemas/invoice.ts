import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';
import { TAX_CATEGORIES } from '@/features/products/schemas/product';
import { paymentSchema } from './payment';

export const INVOICE_STATUSES = ['draft', 'issued', 'paid', 'void'] as const;
export type InvoiceStatus = (typeof INVOICE_STATUSES)[number];

export const INVOICE_STATUS_LABELS: Record<InvoiceStatus, string> = {
    draft: 'Draft',
    issued: 'Issued',
    paid: 'Paid',
    void: 'Void',
};

// Mirrors TransitionInvoiceAction::TRANSITIONS. The server is the authority; this only decides which buttons show.
export const INVOICE_TRANSITIONS: Record<InvoiceStatus, InvoiceStatus[]> = {
    draft: ['issued', 'void'],
    issued: ['paid', 'void'],
    paid: [],
    void: [],
};

export const INVOICE_ACTION_LABELS: Record<InvoiceStatus, string> = {
    draft: 'Back to draft',
    issued: 'Issue invoice',
    paid: 'Mark paid',
    void: 'Void',
};

// Mirrors InvoiceResource lines (10.4b). Money and quantities are decimal strings, never floats.
export const invoiceLineSchema = z.object({
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

// Mirrors InvoiceResource. lines_count is on list rows, lines on show/store responses.
export const invoiceSchema = z.object({
    id: z.number(),
    invoice_seq: z.number(),
    invoice_number: z.string(),
    status: z.enum(INVOICE_STATUSES),
    lock_version: z.number(),
    quote_id: z.number().nullable(),
    title: z.string(),
    account_id: z.number().nullable(),
    contact_id: z.number().nullable(),
    currency: z.string(),
    payment_terms_days: z.number(),
    issue_date: z.string().nullable(),
    due_date: z.string().nullable(),
    notes: z.string().nullable(),
    terms: z.string().nullable(),
    subtotal: z.string(),
    tax_total: z.string(),
    total: z.string(),
    amount_paid: z.string(),
    balance: z.string(),
    issued_at: z.string().nullable(),
    paid_at: z.string().nullable(),
    voided_at: z.string().nullable(),
    void_reason: z.string().nullable(),
    created_by: z.number().nullable(),
    lines_count: z.number().optional(),
    lines: z.array(invoiceLineSchema).optional(),
    payments: z.array(paymentSchema).optional(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Invoice = z.infer<typeof invoiceSchema>;
export type InvoiceLine = z.infer<typeof invoiceLineSchema>;

export const invoicesPageSchema = z.object({
    data: z.array(invoiceSchema),
    meta: paginationMetaSchema,
});

export type InvoicesPage = z.infer<typeof invoicesPageSchema>;

export const INVOICE_SORT_COLUMNS = ['invoice_number', 'title', 'status', 'total', 'issue_date', 'due_date', 'created_at'] as const;
export type InvoiceSortColumn = (typeof INVOICE_SORT_COLUMNS)[number];

// URL-persisted filter/sort/page state for /invoices (8.7). Every field optional; defaults are dropped.
export type InvoicesSearch = {
    q?: string;
    status?: InvoiceStatus;
    sort?: InvoiceSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateInvoicesSearch(search: Record<string, unknown>): InvoicesSearch {
    const out: InvoicesSearch = {};

    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (INVOICE_STATUSES.includes(search.status as InvoiceStatus)) out.status = search.status as InvoiceStatus;
    if (INVOICE_SORT_COLUMNS.includes(search.sort as InvoiceSortColumn)) out.sort = search.sort as InvoiceSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}
