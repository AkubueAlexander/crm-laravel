$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $script:report.Add($m) }

$utf8 = New-Object System.Text.UTF8Encoding($false)
function Read-Text([string]$p) { [System.IO.File]::ReadAllText($p, [System.Text.Encoding]::UTF8) }
function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = [System.IO.Path]::GetDirectoryName($Path)
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $t = $Content.Replace("`r`n", "`n")
    if (-not $t.EndsWith("`n")) { $t += "`n" }
    [System.IO.File]::WriteAllText($Path, $t, $utf8)
}
function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}
function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $raw = Read-Text $Path
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor not found exactly once in $Path : $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, $utf8)
    return 'patched'
}

$routerPath = Join-Path $repo 'frontend\src\router.tsx'
if (-not (Test-Path $routerPath)) { throw 'router.tsx missing' }
if (-not (Test-Path (Join-Path $repo 'frontend\src\shared\components\Modal.tsx'))) { throw 'Modal.tsx missing' }

# ---------------------------------------------------------------- files
$schemaTs = @'
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
'@

$useInventoryTs = @'
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    inventoryItemSchema,
    inventoryItemsPageSchema,
    inventoryMovementsPageSchema,
    type InventoryItem,
    type InventoryItemsPage,
    type InventoryMovementsPage,
    type InventorySortColumn,
    type MovementType,
} from '../schemas/inventory';

// Tenant-namespaced keys (1.2). The root prefix covers lists, details and ledgers: mutations invalidate it as a whole.
export function inventoryRootKey(tenantId: unknown) {
    return ['inventory', tenantId] as const;
}

export type InventoryListParams = {
    page?: number;
    perPage?: number;
    sort?: InventorySortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    active?: 'active' | 'inactive';
    lowStock?: boolean;
    enabled?: boolean;
};

export function useInventoryItems(params: InventoryListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'sku', direction = 'asc', q = '', active, lowStock = false, enabled = true } = params;

    return useQuery({
        queryKey: [...inventoryRootKey(tenant?.id), 'list', { page, perPage, sort, direction, q, active, lowStock }] as const,
        queryFn: async (): Promise<InventoryItemsPage> => {
            const { data } = await apiClient.get('/inventory/items', {
                params: {
                    page,
                    per_page: perPage,
                    sort,
                    direction,
                    q: q || undefined,
                    active: active === 'active' ? 1 : active === 'inactive' ? 0 : undefined,
                    low_stock: lowStock ? 1 : undefined,
                },
            });
            return inventoryItemsPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}

export function useInventoryItem(itemId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: [...inventoryRootKey(tenant?.id), 'detail', itemId ?? 0] as const,
        queryFn: async (): Promise<InventoryItem> => {
            const { data } = await apiClient.get(`/inventory/items/${itemId}`);
            return inventoryItemSchema.parse(data);
        },
        enabled: !!tenant?.id && itemId !== null,
        staleTime: 0,
    });
}

export type MovementsParams = { page?: number; perPage?: number; type?: MovementType };

export function useInventoryMovements(itemId: number | null, params: MovementsParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, type } = params;

    return useQuery({
        queryKey: [...inventoryRootKey(tenant?.id), 'movements', itemId ?? 0, { page, perPage, type }] as const,
        queryFn: async (): Promise<InventoryMovementsPage> => {
            const { data } = await apiClient.get(`/inventory/items/${itemId}/movements`, {
                params: { page, per_page: perPage, type: type || undefined },
            });
            return inventoryMovementsPageSchema.parse(data);
        },
        enabled: !!tenant?.id && itemId !== null,
        placeholderData: keepPreviousData,
    });
}
'@

$useMutationsTs = @'
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { inventoryItemSchema, inventoryMovementSchema, type InventoryItem, type InventoryMovement, type ItemFormValues, type MovementFormValues } from '../schemas/inventory';
import { inventoryRootKey } from './useInventory';

// Field messages from a 422 ({ errors: { quantity: [..] } }), first message per field. Empty for any other error.
export function inventoryFieldErrors(err: unknown): Record<string, string> {
    if (!isApiError(err) || err.response?.status !== 422) return {};
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') out[field] = messages[0];
    }
    return out;
}

function useInvalidateInventory() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    return () => void queryClient.invalidateQueries({ queryKey: inventoryRootKey(tenant?.id) });
}

export type SaveItemVars = { itemId?: number; values: ItemFormValues };

// NOT optimistic: the server owns the item row (SKU is immutable after creation).
export function useSaveInventoryItem() {
    const invalidate = useInvalidateInventory();

    return useMutation({
        mutationFn: async ({ itemId, values }: SaveItemVars): Promise<InventoryItem> => {
            const body = {
                sku: values.sku.trim().toUpperCase(),
                name: values.name,
                unit: values.unit,
                reorder_level: values.reorder_level,
                is_active: values.is_active,
            };
            const { data } = itemId ? await apiClient.put(`/inventory/items/${itemId}`, body) : await apiClient.post('/inventory/items', body);
            return inventoryItemSchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export type RecordMovementVars = { itemId: number; values: MovementFormValues };

// NOT optimistic: it is an append-only ledger and the server decides the balance (and refuses short issues).
export function useRecordMovement() {
    const invalidate = useInvalidateInventory();

    return useMutation({
        mutationFn: async ({ itemId, values }: RecordMovementVars): Promise<InventoryMovement> => {
            const { data } = await apiClient.post(`/inventory/items/${itemId}/movements`, {
                type: values.type,
                quantity: values.quantity,
                ...(values.reference ? { reference: values.reference } : {}),
                ...(values.note ? { note: values.note } : {}),
            });
            return inventoryMovementSchema.parse(data);
        },
        onSuccess: invalidate,
    });
}
'@

$itemFormTsx = @'
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { inventoryFieldErrors, useSaveInventoryItem } from '../api/useInventoryMutations';
import { itemFormSchema, type InventoryItem, type ItemFormValues } from '../schemas/inventory';

const FIELDS = ['sku', 'name', 'unit', 'reorder_level'] as const;

function blank(item?: InventoryItem | null): ItemFormValues {
    return item
        ? { sku: item.sku, name: item.name, unit: item.unit, reorder_level: item.reorder_level, is_active: item.is_active }
        : { sku: '', name: '', unit: 'pcs', reorder_level: '0', is_active: true };
}

// 11.0c: create / edit an item. Never touches stock (movements do). SKU is read-only on edit.
export function ItemFormModal({ open, onClose, item }: { open: boolean; onClose: () => void; item?: InventoryItem | null }) {
    const save = useSaveInventoryItem();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<ItemFormValues>({ resolver: zodResolver(itemFormSchema), defaultValues: blank(item) });
    const editing = !!item;

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset(blank(item));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, item?.id]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await save.mutateAsync({ itemId: item?.id, values });
            onClose();
        } catch (err) {
            const fields = inventoryFieldErrors(err);
            let shown = false;
            for (const f of FIELDS) {
                if (fields[f]) {
                    form.setError(f, { message: fields[f] });
                    shown = true;
                }
            }
            if (!shown) setFormError(apiErrorMessage(err, 'Could not save the item. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title={editing ? 'Edit item' : 'New item'}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="item-sku" className="mb-1 block text-sm font-medium">
                            SKU
                        </label>
                        <input
                            id="item-sku"
                            readOnly={editing}
                            aria-invalid={errors.sku ? true : undefined}
                            className={`${inputClass} ${editing ? 'bg-gray-50 text-[var(--color-ink-muted)]' : ''}`}
                            {...form.register('sku')}
                        />
                        {errors.sku?.message && (
                            <p role="alert" className={errClass}>
                                {errors.sku.message}
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="item-unit" className="mb-1 block text-sm font-medium">
                            Unit
                        </label>
                        <input id="item-unit" aria-invalid={errors.unit ? true : undefined} className={inputClass} {...form.register('unit')} />
                        {errors.unit?.message && (
                            <p role="alert" className={errClass}>
                                {errors.unit.message}
                            </p>
                        )}
                    </div>
                </div>
                <div>
                    <label htmlFor="item-name" className="mb-1 block text-sm font-medium">
                        Name
                    </label>
                    <input id="item-name" aria-invalid={errors.name ? true : undefined} className={inputClass} {...form.register('name')} />
                    {errors.name?.message && (
                        <p role="alert" className={errClass}>
                            {errors.name.message}
                        </p>
                    )}
                </div>
                <div>
                    <label htmlFor="item-reorder" className="mb-1 block text-sm font-medium">
                        Reorder level (0 = no alert)
                    </label>
                    <input id="item-reorder" inputMode="decimal" aria-invalid={errors.reorder_level ? true : undefined} className={inputClass} {...form.register('reorder_level')} />
                    {errors.reorder_level?.message && (
                        <p role="alert" className={errClass}>
                            {errors.reorder_level.message}
                        </p>
                    )}
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" {...form.register('is_active')} />
                    Active (inactive items cannot take stock movements)
                </label>
                {formError && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {formError}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} disabled={form.formState.isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                        Cancel
                    </button>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Saving...' : editing ? 'Save changes' : 'Create item'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
'@

$movementModalTsx = @'
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { inventoryFieldErrors, useRecordMovement } from '../api/useInventoryMutations';
import { MOVEMENT_TYPES, MOVEMENT_TYPE_LABELS, formatQty, movementFormSchema, type InventoryItem, type MovementFormValues } from '../schemas/inventory';

const FIELDS = ['type', 'quantity', 'reference', 'note'] as const;
const BLANK: MovementFormValues = { type: 'receipt', quantity: '', reference: '', note: '' };

// 11.0c: record a stock movement. Not optimistic: the server computes the balance and refuses an issue larger than stock.
export function MovementModal({ open, onClose, item }: { open: boolean; onClose: () => void; item: InventoryItem }) {
    const record = useRecordMovement();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<MovementFormValues>({ resolver: zodResolver(movementFormSchema), defaultValues: BLANK });
    const type = form.watch('type');

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset(BLANK);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await record.mutateAsync({ itemId: item.id, values });
            onClose();
        } catch (err) {
            const fields = inventoryFieldErrors(err);
            let shown = false;
            for (const f of FIELDS) {
                if (fields[f]) {
                    form.setError(f, { message: fields[f] });
                    shown = true;
                }
            }
            if (!shown) setFormError(fields.item ?? apiErrorMessage(err, 'Could not record the movement. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title="Record stock movement">
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <p className="text-sm text-[var(--color-ink-muted)]">
                    {item.sku} - {item.name}. On hand: {formatQty(item.on_hand)} {item.unit}.
                </p>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="mv-type" className="mb-1 block text-sm font-medium">
                            Type
                        </label>
                        <select id="mv-type" className={inputClass} {...form.register('type')}>
                            {MOVEMENT_TYPES.map((t) => (
                                <option key={t} value={t}>
                                    {MOVEMENT_TYPE_LABELS[t]}
                                </option>
                            ))}
                        </select>
                        {errors.type?.message && (
                            <p role="alert" className={errClass}>
                                {errors.type.message}
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="mv-quantity" className="mb-1 block text-sm font-medium">
                            Quantity ({item.unit})
                        </label>
                        <input id="mv-quantity" inputMode="decimal" aria-invalid={errors.quantity ? true : undefined} className={inputClass} {...form.register('quantity')} />
                        {errors.quantity?.message && (
                            <p role="alert" className={errClass}>
                                {errors.quantity.message}
                            </p>
                        )}
                    </div>
                </div>
                {type === 'adjustment' && <p className="text-xs text-[var(--color-ink-muted)]">Adjustments may be negative (e.g. -2 for a stock-take loss) and need a reason.</p>}
                <div>
                    <label htmlFor="mv-reference" className="mb-1 block text-sm font-medium">
                        Reference
                    </label>
                    <input id="mv-reference" className={inputClass} {...form.register('reference')} />
                    {errors.reference?.message && (
                        <p role="alert" className={errClass}>
                            {errors.reference.message}
                        </p>
                    )}
                </div>
                <div>
                    <label htmlFor="mv-note" className="mb-1 block text-sm font-medium">
                        {type === 'adjustment' ? 'Reason' : 'Note'}
                    </label>
                    <textarea id="mv-note" rows={3} aria-invalid={errors.note ? true : undefined} className={inputClass} {...form.register('note')} />
                    {errors.note?.message && (
                        <p role="alert" className={errClass}>
                            {errors.note.message}
                        </p>
                    )}
                </div>
                {formError && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {formError}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} disabled={form.formState.isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                        Cancel
                    </button>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Saving...' : 'Record movement'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
'@

$inventoryPageTsx = @'
import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useInventoryItems } from '../api/useInventory';
import { formatQty, validateInventorySearch, type InventoryItem, type InventorySearch, type InventorySortColumn } from '../schemas/inventory';
import { ItemFormModal } from './ItemFormModal';

const columnHelper = createColumnHelper<InventoryItem>();
const EMPTY: InventoryItem[] = [];

export function InventoryPage() {
    const allowed = usePermission('inventory.view');
    const canManage = usePermission('inventory.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateInventorySearch(raw);

    const page = search.page ?? 1;
    const sort: InventorySortColumn = search.sort ?? 'sku';
    const direction = search.direction ?? 'asc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<InventorySearch>) {
        void navigate({ to: '/inventory', search: validateInventorySearch({ ...search, ...patch }), replace: true });
    }

    const [newOpen, setNewOpen] = useState(false);
    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useInventoryItems({
        page,
        sort,
        direction,
        q: search.q,
        active: search.active,
        lowStock: search.low_stock === true,
        enabled: allowed,
    });

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('sku', {
                header: 'SKU',
                cell: (info) => (
                    <Link to="/inventory/$itemId" params={{ itemId: String(info.row.original.id) }} className="font-mono text-xs underline">
                        {info.getValue()}
                    </Link>
                ),
            }),
            columnHelper.accessor('name', {
                header: 'Name',
                cell: (info) => <span className="font-medium">{info.getValue()}</span>,
            }),
            columnHelper.accessor('on_hand', {
                header: 'On hand',
                cell: (info) => (
                    <span className="inline-flex items-center gap-2">
                        {formatQty(info.getValue())} {info.row.original.unit}
                        {info.row.original.low_stock && <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Low stock</span>}
                    </span>
                ),
            }),
            columnHelper.accessor('reorder_level', {
                header: 'Reorder at',
                cell: (info) => (Number(info.getValue()) > 0 ? formatQty(info.getValue()) : '-'),
            }),
            columnHelper.accessor('is_active', {
                header: 'Status',
                cell: (info) => (
                    <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{info.getValue() ? 'Active' : 'Inactive'}</span>
                ),
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => (
                    <Link to="/inventory/$itemId" params={{ itemId: String(row.original.id) }} className="rounded-md border px-2 py-1 text-xs">
                        Open
                    </Link>
                ),
                enableSorting: false,
            }),
        ],
        [],
    );

    const sorting: SortingState = [{ id: sort, desc: direction === 'desc' }];

    const table = useReactTable({
        data: data?.data ?? EMPTY,
        columns,
        state: { sorting },
        onSortingChange: (updater) => {
            const next = typeof updater === 'function' ? updater(sorting) : updater;
            const first = next[0];
            if (!first) return;
            setSearch({ sort: first.id as InventorySortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to inventory.</p>;
    }

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.active || search.low_stock);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Inventory</h1>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <button type="button" onClick={() => setNewOpen(true)} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                            New item
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    placeholder="Search SKU or name..."
                    value={qInput}
                    onChange={(e) => setQInput(e.target.value)}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                <select
                    aria-label="Filter by status"
                    value={search.active ?? ''}
                    onChange={(e) => setSearch({ active: (e.target.value || undefined) as InventorySearch['active'], page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All items</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" checked={search.low_stock === true} onChange={(e) => setSearch({ low_stock: e.target.checked ? true : undefined, page: undefined })} />
                    Low stock only
                </label>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/inventory', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load inventory.")}
                </p>
            )}

            {isPending ? (
                <p className="text-sm text-gray-500">Loading...</p>
            ) : (
                <table className="w-full border-collapse text-sm">
                    <thead>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <tr key={headerGroup.id}>
                                {headerGroup.headers.map((header) => {
                                    const canSort = header.column.getCanSort();
                                    const sortDir = header.column.getIsSorted();
                                    return (
                                        <th
                                            key={header.id}
                                            onClick={canSort ? header.column.getToggleSortingHandler() : undefined}
                                            className={`border-b px-3 py-2 text-left font-medium ${canSort ? 'cursor-pointer select-none' : ''}`}
                                        >
                                            {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                            {sortDir === 'asc' ? ' \u25b2' : sortDir === 'desc' ? ' \u25bc' : ''}
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.map((row) => (
                            <tr key={row.original.id}>
                                {row.getVisibleCells().map((cell) => (
                                    <td key={cell.id} className="border-b px-3 py-2 align-top">
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {table.getRowModel().rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="px-3 py-6 text-center text-gray-500">
                                    {hasFilters ? 'No items match these filters.' : 'No inventory items yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            )}

            {meta && (
                <div className="mt-3 flex items-center justify-between text-sm text-gray-600">
                    <span>
                        {meta.from ?? 0}-{meta.to ?? 0} of {meta.total}
                    </span>
                    <div className="flex gap-2">
                        <button type="button" disabled={page <= 1} onClick={() => setSearch({ page: page - 1 })} className="rounded-md border px-2 py-1 disabled:opacity-40">
                            Previous
                        </button>
                        <button type="button" disabled={page >= meta.last_page} onClick={() => setSearch({ page: page + 1 })} className="rounded-md border px-2 py-1 disabled:opacity-40">
                            Next
                        </button>
                    </div>
                </div>
            )}

            <ItemFormModal open={newOpen} onClose={() => setNewOpen(false)} />
        </div>
    );
}
'@

$itemDetailTsx = @'
import { useState } from 'react';
import { Link, useParams } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useInventoryItem, useInventoryMovements } from '../api/useInventory';
import { MOVEMENT_TYPES, MOVEMENT_TYPE_SHORT, formatQty, type MovementType } from '../schemas/inventory';
import { ItemFormModal } from './ItemFormModal';
import { MovementModal } from './MovementModal';

// 11.0c: item summary + read-only, append-only ledger (newest first). Movements are the only way stock changes.
export function ItemDetail() {
    const allowed = usePermission('inventory.view');
    const canManage = usePermission('inventory.manage');
    const params = useParams({ strict: false }) as { itemId?: string };
    const itemId = Number(params.itemId);
    const validId = Number.isInteger(itemId) && itemId > 0;

    const [page, setPage] = useState(1);
    const [type, setType] = useState<MovementType | ''>('');
    const [moveOpen, setMoveOpen] = useState(false);
    const [editOpen, setEditOpen] = useState(false);

    const item = useInventoryItem(validId && allowed ? itemId : null);
    const ledger = useInventoryMovements(validId && allowed ? itemId : null, { page, type: type || undefined });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to inventory.</p>;
    if (!validId) return <p className="text-sm text-red-700">Invalid item.</p>;
    if (item.isPending) return <p className="text-sm text-gray-500">Loading...</p>;
    if (item.isError || !item.data) {
        return (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {apiErrorMessage(item.error, "Couldn't load this item.")}
            </p>
        );
    }

    const it = item.data;
    const meta = ledger.data?.meta;
    const rows = ledger.data?.data ?? [];

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/inventory" className="text-[var(--color-ink-muted)] underline">
                    Inventory
                </Link>
            </div>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">
                    <span className="font-mono text-base">{it.sku}</span> {it.name}
                </h1>
                {canManage && (
                    <div className="flex gap-2">
                        <button type="button" onClick={() => setEditOpen(true)} className="rounded-md border px-3 py-1.5 text-sm">
                            Edit
                        </button>
                        <button
                            type="button"
                            onClick={() => setMoveOpen(true)}
                            disabled={!it.is_active}
                            title={it.is_active ? undefined : 'Inactive items cannot take stock movements'}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                        >
                            Record movement
                        </button>
                    </div>
                )}
            </div>

            <dl className="mb-6 grid max-w-xl grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
                <div>
                    <dt className="text-[var(--color-ink-muted)]">On hand</dt>
                    <dd className="font-medium">
                        {formatQty(it.on_hand)} {it.unit}
                        {it.low_stock && <span className="ml-2 rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Low stock</span>}
                    </dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Reorder at</dt>
                    <dd>{Number(it.reorder_level) > 0 ? formatQty(it.reorder_level) : '-'}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Unit</dt>
                    <dd>{it.unit}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Status</dt>
                    <dd>{it.is_active ? 'Active' : 'Inactive'}</dd>
                </div>
            </dl>

            <section>
                <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <h2 className="text-base font-medium text-[var(--color-ink)]">Stock ledger</h2>
                        <p className="text-xs text-[var(--color-ink-muted)]">Append-only: entries are never edited or deleted. Corrections are new adjustments.</p>
                    </div>
                    <select
                        aria-label="Filter by movement type"
                        value={type}
                        onChange={(e) => {
                            setType((e.target.value || '') as MovementType | '');
                            setPage(1);
                        }}
                        className="rounded-md border px-2 py-1.5 text-sm"
                    >
                        <option value="">All types</option>
                        {MOVEMENT_TYPES.map((t) => (
                            <option key={t} value={t}>
                                {MOVEMENT_TYPE_SHORT[t]}
                            </option>
                        ))}
                    </select>
                </div>

                {ledger.isError && (
                    <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {apiErrorMessage(ledger.error, "Couldn't load the ledger.")}
                    </p>
                )}

                <div className="overflow-x-auto rounded-md bg-gray-50">
                    <table className="w-full border-collapse text-sm">
                        <thead>
                            <tr>
                                <th className="border-b px-3 py-2 text-left font-medium">When</th>
                                <th className="border-b px-3 py-2 text-left font-medium">Type</th>
                                <th className="border-b px-3 py-2 text-right font-medium">Change</th>
                                <th className="border-b px-3 py-2 text-right font-medium">Balance</th>
                                <th className="border-b px-3 py-2 text-left font-medium">Reference / note</th>
                                <th className="border-b px-3 py-2 text-left font-medium">By</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((m) => {
                                const negative = m.quantity_delta.startsWith('-');
                                return (
                                    <tr key={m.id}>
                                        <td className="border-b px-3 py-2 align-top whitespace-nowrap">{m.created_at ? new Date(m.created_at).toLocaleString() : '-'}</td>
                                        <td className="border-b px-3 py-2 align-top">{MOVEMENT_TYPE_SHORT[m.type]}</td>
                                        <td className={`border-b px-3 py-2 text-right align-top font-mono ${negative ? 'text-red-700' : 'text-green-700'}`}>
                                            {negative ? '' : '+'}
                                            {formatQty(m.quantity_delta)}
                                        </td>
                                        <td className="border-b px-3 py-2 text-right align-top font-mono">{formatQty(m.balance_after)}</td>
                                        <td className="border-b px-3 py-2 align-top">
                                            <div>{m.reference ?? '-'}</div>
                                            {m.note && <div className="text-xs text-[var(--color-ink-muted)]">{m.note}</div>}
                                        </td>
                                        <td className="border-b px-3 py-2 align-top text-xs">{m.recorded_by ? `User #${m.recorded_by}` : '-'}</td>
                                    </tr>
                                );
                            })}
                            {!ledger.isPending && rows.length === 0 && (
                                <tr>
                                    <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                                        No movements yet.
                                    </td>
                                </tr>
                            )}
                            {ledger.isPending && (
                                <tr>
                                    <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                                        Loading...
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {meta && (
                    <div className="mt-3 flex items-center justify-between text-sm text-gray-600">
                        <span>
                            {meta.from ?? 0}-{meta.to ?? 0} of {meta.total}
                        </span>
                        <div className="flex gap-2">
                            <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-md border px-2 py-1 disabled:opacity-40">
                                Previous
                            </button>
                            <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)} className="rounded-md border px-2 py-1 disabled:opacity-40">
                                Next
                            </button>
                        </div>
                    </div>
                )}
            </section>

            <MovementModal open={moveOpen} onClose={() => setMoveOpen(false)} item={it} />
            <ItemFormModal open={editOpen} onClose={() => setEditOpen(false)} item={it} />
        </div>
    );
}
'@

$routeBlock = @'
// 11.0c: inventory. The list keeps filters/sort/page in the URL (8.7).
const inventoryRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/inventory',
    validateSearch: validateInventorySearch,
    component: InventoryPage,
});

const inventoryDetailRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/inventory/$itemId',
    component: ItemDetail,
});

'@

# ---------------------------------------------------------------- patches (preflight on ORIGINAL files)
$patches = New-Object System.Collections.ArrayList
$importAnchor = "import { validateInvoicesSearch } from '@/features/invoices/schemas/invoice';"
$importRepl = $importAnchor + "`n" + "import { InventoryPage } from '@/features/inventory/components/InventoryPage';" + "`n" + "import { ItemDetail } from '@/features/inventory/components/ItemDetail';" + "`n" + "import { validateInventorySearch } from '@/features/inventory/schemas/inventory';"
[void]$patches.Add(@{ Path = $routerPath; Anchor = $importAnchor; Replacement = $importRepl; Skip = "features/inventory/components/InventoryPage" })

$treeAnchor = 'const routeTree = rootRoute.addChildren(['
[void]$patches.Add(@{ Path = $routerPath; Anchor = $treeAnchor; Replacement = ($routeBlock + $treeAnchor); Skip = 'const inventoryRoute = createRoute' })

$childAnchor = 'invoicesRoute, invoiceDetailRoute]'
[void]$patches.Add(@{ Path = $routerPath; Anchor = $childAnchor; Replacement = 'invoicesRoute, invoiceDetailRoute, inventoryRoute, inventoryDetailRoute]'; Skip = 'inventoryRoute, inventoryDetailRoute]' })

foreach ($p in $patches) {
    $t = (Read-Text $p.Path).Replace("`r`n", "`n")
    if ($t.Contains($p.Skip)) { continue }
    $c = Count-Sub $t $p.Anchor
    if ($c -ne 1) { throw "PREFLIGHT: anchor occurs $c times (need 1) in $($p.Path): $($p.Anchor)" }
}

# ---------------------------------------------------------------- write
$base = 'frontend\src\features\inventory'
Write-NoBom "$base\schemas\inventory.ts" $schemaTs
Write-NoBom "$base\api\useInventory.ts" $useInventoryTs
Write-NoBom "$base\api\useInventoryMutations.ts" $useMutationsTs
Write-NoBom "$base\components\ItemFormModal.tsx" $itemFormTsx
Write-NoBom "$base\components\MovementModal.tsx" $movementModalTsx
Write-NoBom "$base\components\InventoryPage.tsx" $inventoryPageTsx
Write-NoBom "$base\components\ItemDetail.tsx" $itemDetailTsx
Say 'Wrote 7 inventory files.'

foreach ($p in $patches) {
    $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip
    Say "router.tsx patch: $res"
}

# ---------------------------------------------------------------- type check
Push-Location (Join-Path $repo 'frontend')
$ErrorActionPreference = 'Continue'
$tsc = & npx.cmd tsc --noEmit 2>&1 | Out-String
$tscCode = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
Pop-Location
if ($tscCode -eq 0) { Say 'tsc --noEmit: clean' } else { Say "tsc --noEmit: FAILED`n$tsc" }

Say 'Frontend-only chunk: no backend tests to run.'
Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): AppShell.tsx (Inventory nav item already points at /inventory), backend inventory files, features/quotes/schemas/quote.ts.'
