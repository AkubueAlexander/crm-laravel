import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

export const MOVEMENT_TYPES = ['receipt', 'issue', 'adjustment'] as const;
export type MovementType = (typeof MOVEMENT_TYPES)[number];

export const MOVEMENT_TYPE_LABELS: Record<MovementType, string> = {
    receipt: 'Receipt (stock in)',
    issue: 'Issue (stock out)',
    adjustment: 'Adjustment (correction)',
};

export const MOVEMENT_TYPE_SHORT: Record<MovementType, string> = {
    receipt: 'Receipt',
    issue: 'Issue',
    adjustment: 'Adjustment',
};

// Mirrors InventoryItemResource (11.0b). Quantities are decimal strings ('10.000'), never floats.
export const inventoryItemSchema = z.object({
    id: z.number(),
    sku: z.string(),
    name: z.string(),
    unit: z.string(),
    on_hand: z.string(),
    reorder_level: z.string(),
    is_active: z.boolean(),
    low_stock: z.boolean(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

// Mirrors InventoryMovementResource (11.0b).
export const inventoryMovementSchema = z.object({
    id: z.number(),
    inventory_item_id: z.number(),
    type: z.enum(MOVEMENT_TYPES),
    quantity_delta: z.string(),
    balance_after: z.string(),
    reference: z.string().nullable(),
    note: z.string().nullable(),
    recorded_by: z.number().nullable(),
    created_at: z.string().nullable(),
});

export type InventoryItem = z.infer<typeof inventoryItemSchema>;
export type InventoryMovement = z.infer<typeof inventoryMovementSchema>;

export const inventoryItemsPageSchema = z.object({ data: z.array(inventoryItemSchema), meta: paginationMetaSchema });
export const inventoryMovementsPageSchema = z.object({ data: z.array(inventoryMovementSchema), meta: paginationMetaSchema });
export type InventoryItemsPage = z.infer<typeof inventoryItemsPageSchema>;
export type InventoryMovementsPage = z.infer<typeof inventoryMovementsPageSchema>;

// Display only: '10.000' -> '10', '2.500' -> '2.5'.
export function formatQty(value: string): string {
    const n = Number(value);
    if (!Number.isFinite(n)) return value;
    return n.toLocaleString(undefined, { maximumFractionDigits: 3 });
}

const QTY_RE = /^\d+(\.\d{1,3})?$/;
const SIGNED_QTY_RE = /^-?\d+(\.\d{1,3})?$/;

// UX layer only: the Form Requests / Actions are the boundary.
export const itemFormSchema = z.object({
    sku: z.string().trim().min(1, 'SKU is required.').max(64, 'SKU is too long.'),
    name: z.string().trim().min(1, 'Name is required.').max(255, 'Name is too long.'),
    unit: z.string().trim().min(1, 'Unit is required.').max(32, 'Unit is too long.'),
    reorder_level: z.string().trim().regex(QTY_RE, 'Use a number of 0 or more, up to 3 decimals.'),
    is_active: z.boolean(),
});
export type ItemFormValues = z.infer<typeof itemFormSchema>;

export const movementFormSchema = z
    .object({
        type: z.enum(MOVEMENT_TYPES),
        quantity: z.string().trim(),
        reference: z.string().trim().max(255, 'Reference is too long.'),
        note: z.string().trim().max(1000, 'Note is too long.'),
    })
    .superRefine((v, ctx) => {
        if (v.quantity === '') {
            ctx.addIssue({ code: 'custom', path: ['quantity'], message: 'Enter a quantity.' });
            return;
        }
        if (!SIGNED_QTY_RE.test(v.quantity)) {
            ctx.addIssue({ code: 'custom', path: ['quantity'], message: 'Use a number with up to 3 decimals.' });
            return;
        }
        const n = Number(v.quantity);
        if (n === 0) ctx.addIssue({ code: 'custom', path: ['quantity'], message: 'Quantity cannot be zero.' });
        else if (v.type !== 'adjustment' && n < 0) ctx.addIssue({ code: 'custom', path: ['quantity'], message: 'Only adjustments can be negative.' });
        if (v.type === 'adjustment' && v.note === '') ctx.addIssue({ code: 'custom', path: ['note'], message: 'An adjustment needs a reason.' });
    });
export type MovementFormValues = z.infer<typeof movementFormSchema>;

export const INVENTORY_SORT_COLUMNS = ['sku', 'name', 'on_hand', 'reorder_level', 'created_at'] as const;
export type InventorySortColumn = (typeof INVENTORY_SORT_COLUMNS)[number];

// URL-persisted filter/sort/page state for /inventory (8.7).
export type InventorySearch = {
    q?: string;
    active?: 'active' | 'inactive';
    low_stock?: true;
    sort?: InventorySortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateInventorySearch(search: Record<string, unknown>): InventorySearch {
    const out: InventorySearch = {};

    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (search.active === 'active' || search.active === 'inactive') out.active = search.active;
    if (search.low_stock === true || search.low_stock === 'true' || search.low_stock === 1 || search.low_stock === '1') out.low_stock = true;
    if (INVENTORY_SORT_COLUMNS.includes(search.sort as InventorySortColumn)) out.sort = search.sort as InventorySortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}
