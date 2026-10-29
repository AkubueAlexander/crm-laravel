import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

export const PO_STATUSES = ['draft', 'ordered', 'partially_received', 'received', 'cancelled'] as const;
export type PoStatus = (typeof PO_STATUSES)[number];

export const PO_STATUS_LABELS: Record<PoStatus, string> = {
    draft: 'Draft',
    ordered: 'Ordered',
    partially_received: 'Partially received',
    received: 'Received',
    cancelled: 'Cancelled',
};

export const PO_STATUS_CLASSES: Record<PoStatus, string> = {
    draft: 'bg-gray-100 text-gray-700',
    ordered: 'bg-blue-100 text-blue-800',
    partially_received: 'bg-amber-100 text-amber-800',
    received: 'bg-green-100 text-green-800',
    cancelled: 'bg-red-100 text-red-800',
};

// Mirrors TransitionPurchaseOrderAction::TRANSITIONS. The server is the authority; this only decides which buttons show.
export const PO_TRANSITIONS: Record<PoStatus, PoStatus[]> = {
    draft: ['ordered', 'cancelled'],
    ordered: ['cancelled'],
    partially_received: [],
    received: [],
    cancelled: [],
};

// Mirrors SupplierResource (11.2b).
export const supplierSchema = z.object({
    id: z.number(),
    name: z.string(),
    email: z.string().nullable(),
    phone: z.string().nullable(),
    notes: z.string().nullable(),
    is_active: z.boolean(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

// Mirrors PurchaseOrderResource lines. Money and quantities are decimal strings, never floats.
export const poLineSchema = z.object({
    id: z.number(),
    position: z.number(),
    inventory_item_id: z.number(),
    sku: z.string().nullable(),
    name: z.string().nullable(),
    unit: z.string().nullable(),
    quantity: z.string(),
    unit_cost: z.string(),
    received_quantity: z.string(),
    remaining: z.string(),
    line_total: z.string(),
});

// Mirrors PurchaseOrderResource. lines_count is on list rows, lines on show/store/transition responses.
export const purchaseOrderSchema = z.object({
    id: z.number(),
    po_seq: z.number(),
    po_number: z.string(),
    status: z.enum(PO_STATUSES),
    lock_version: z.number(),
    supplier_id: z.number(),
    supplier: z.object({ id: z.number(), name: z.string() }).optional(),
    currency: z.string(),
    expected_date: z.string().nullable(),
    notes: z.string().nullable(),
    subtotal: z.string(),
    ordered_at: z.string().nullable(),
    received_at: z.string().nullable(),
    cancelled_at: z.string().nullable(),
    cancel_reason: z.string().nullable(),
    created_by: z.number().nullable(),
    lines_count: z.number().optional(),
    lines: z.array(poLineSchema).optional(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Supplier = z.infer<typeof supplierSchema>;
export type PoLine = z.infer<typeof poLineSchema>;
export type PurchaseOrder = z.infer<typeof purchaseOrderSchema>;

export const suppliersPageSchema = z.object({ data: z.array(supplierSchema), meta: paginationMetaSchema });
export const purchaseOrdersPageSchema = z.object({ data: z.array(purchaseOrderSchema), meta: paginationMetaSchema });
export type SuppliersPage = z.infer<typeof suppliersPageSchema>;
export type PurchaseOrdersPage = z.infer<typeof purchaseOrdersPageSchema>;

const QTY_RE = /^\d+(\.\d{1,3})?$/;
const COST_RE = /^\d+(\.\d{1,2})?$/;

// ---- forms (UX layer only: the Form Requests / Actions are the boundary)
export const supplierFormSchema = z.object({
    name: z.string().trim().min(1, 'Name is required.').max(160, 'Name is too long.'),
    email: z
        .string()
        .trim()
        .max(190, 'Email is too long.')
        .refine((v) => v === '' || /^\S+@\S+\.\S+$/.test(v), 'Enter a valid email address.'),
    phone: z.string().trim().max(40, 'Phone is too long.'),
    notes: z.string().trim().max(2000, 'Notes are too long.'),
    is_active: z.boolean(),
});
export type SupplierFormValues = z.infer<typeof supplierFormSchema>;

export const poLineFormSchema = z.object({
    inventory_item_id: z.string().min(1, 'Choose an item.'),
    quantity: z
        .string()
        .trim()
        .regex(QTY_RE, 'Use a number with up to 3 decimals.')
        .refine((v) => Number(v) > 0, 'Quantity must be greater than zero.'),
    unit_cost: z.string().trim().regex(COST_RE, 'Use a cost with up to 2 decimals.'),
});

export const poFormSchema = z
    .object({
        supplier_id: z.string().min(1, 'Choose a supplier.'),
        currency: z.string().trim().regex(/^[A-Za-z]{3}$/, 'Use a 3-letter currency code.'),
        expected_date: z.string().trim().refine((v) => v === '' || /^\d{4}-\d{2}-\d{2}$/.test(v), 'Use a valid date.'),
        notes: z.string().trim().max(2000, 'Notes are too long.'),
        lines: z.array(poLineFormSchema).min(1, 'Add at least one line.'),
    })
    .superRefine((v, ctx) => {
        const seen = new Set<string>();
        v.lines.forEach((l, i) => {
            if (l.inventory_item_id && seen.has(l.inventory_item_id)) {
                ctx.addIssue({ code: 'custom', path: ['lines', i, 'inventory_item_id'], message: 'Each item can appear only once.' });
            }
            seen.add(l.inventory_item_id);
        });
    });
export type PoFormValues = z.infer<typeof poFormSchema>;
export type PoLineFormValues = z.infer<typeof poLineFormSchema>;

export function emptyPoLine(): PoLineFormValues {
    return { inventory_item_id: '', quantity: '1', unit_cost: '0.00' };
}

// Display-only estimate (integer thousandths x cents, rounded like the server). The server computes the real total.
export function estimateLineTotal(quantity: string, unitCost: string): number | null {
    const q = quantity.trim();
    const c = unitCost.trim();
    if (!QTY_RE.test(q) || !COST_RE.test(c)) return null;
    const milli = Math.round(Number(q) * 1000);
    const cents = Math.round(Number(c) * 100);
    return Math.floor((milli * cents + 500) / 1000) / 100;
}

export const receiveFormSchema = z
    .object({
        note: z.string().trim().max(1000, 'Note is too long.'),
        rows: z.array(
            z.object({
                line_id: z.number(),
                label: z.string(),
                unit: z.string(),
                remaining: z.string(),
                quantity: z.string().trim(),
            }),
        ),
    })
    .superRefine((v, ctx) => {
        let filled = 0;
        v.rows.forEach((r, i) => {
            if (r.quantity === '') return;
            if (!QTY_RE.test(r.quantity) || Number(r.quantity) <= 0) {
                ctx.addIssue({ code: 'custom', path: ['rows', i, 'quantity'], message: 'Enter a positive quantity (up to 3 decimals).' });
                return;
            }
            filled += 1;
            if (Number(r.quantity) > Number(r.remaining)) {
                ctx.addIssue({ code: 'custom', path: ['rows', i, 'quantity'], message: `Only ${Number(r.remaining)} still to receive.` });
            }
        });
        if (filled === 0 && v.rows.length > 0) {
            ctx.addIssue({ code: 'custom', path: ['rows', 0, 'quantity'], message: 'Enter a quantity for at least one line.' });
        }
    });
export type ReceiveFormValues = z.infer<typeof receiveFormSchema>;

export const cancelFormSchema = z.object({
    reason: z.string().trim().min(1, 'Give a reason for cancelling.').max(2000, 'Reason is too long.'),
});
export type CancelFormValues = z.infer<typeof cancelFormSchema>;

// ---- URL-persisted list state (8.7). Both validators never throw and drop anything malformed or empty.
export const PO_SORT_COLUMNS = ['po_number', 'status', 'subtotal', 'expected_date', 'created_at'] as const;
export type PoSortColumn = (typeof PO_SORT_COLUMNS)[number];

export type PurchaseOrdersSearch = {
    q?: string;
    status?: PoStatus;
    sort?: PoSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

function cleanQ(raw: unknown): string | undefined {
    const v = typeof raw === 'number' ? String(raw) : raw;
    return typeof v === 'string' && v.trim() !== '' ? v.trim() : undefined;
}

function cleanPage(raw: unknown): number | undefined {
    const page = Number(raw);
    return Number.isInteger(page) && page > 1 ? page : undefined;
}

export function validatePurchaseOrdersSearch(search: Record<string, unknown>): PurchaseOrdersSearch {
    const out: PurchaseOrdersSearch = {};
    const q = cleanQ(search.q);
    if (q) out.q = q;
    if (PO_STATUSES.includes(search.status as PoStatus)) out.status = search.status as PoStatus;
    if (PO_SORT_COLUMNS.includes(search.sort as PoSortColumn)) out.sort = search.sort as PoSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;
    const page = cleanPage(search.page);
    if (page) out.page = page;
    return out;
}

export const SUPPLIER_SORT_COLUMNS = ['name', 'created_at'] as const;
export type SupplierSortColumn = (typeof SUPPLIER_SORT_COLUMNS)[number];

export type SuppliersSearch = {
    q?: string;
    active?: 'active' | 'inactive';
    sort?: SupplierSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

export function validateSuppliersSearch(search: Record<string, unknown>): SuppliersSearch {
    const out: SuppliersSearch = {};
    const q = cleanQ(search.q);
    if (q) out.q = q;
    if (search.active === 'active' || search.active === 'inactive') out.active = search.active;
    if (SUPPLIER_SORT_COLUMNS.includes(search.sort as SupplierSortColumn)) out.sort = search.sort as SupplierSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;
    const page = cleanPage(search.page);
    if (page) out.page = page;
    return out;
}
