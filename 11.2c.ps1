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
$invPagePath = Join-Path $repo 'frontend\src\features\inventory\components\InventoryPage.tsx'
foreach ($need in @($routerPath, $invPagePath, (Join-Path $repo 'frontend\src\features\inventory\api\useInventory.ts'), (Join-Path $repo 'routes\purchasing.php'))) {
    if (-not (Test-Path $need)) { throw "Missing prerequisite: $need" }
}

# ---------------------------------------------------------------- files
$schemaTs = @'
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
'@

$usePurchasingTs = @'
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    purchaseOrderSchema,
    purchaseOrdersPageSchema,
    suppliersPageSchema,
    type PoSortColumn,
    type PoStatus,
    type PurchaseOrder,
    type PurchaseOrdersPage,
    type Supplier,
    type SupplierSortColumn,
    type SuppliersPage,
} from '../schemas/purchasing';

// Tenant-namespaced keys (1.2). The root prefix covers suppliers, lists and details: mutations invalidate it as a whole.
export function purchasingRootKey(tenantId: unknown) {
    return ['purchasing', tenantId] as const;
}

export function purchaseOrderDetailKey(tenantId: unknown, poId: number) {
    return [...purchasingRootKey(tenantId), 'purchase-order', poId] as const;
}

export type SuppliersListParams = {
    page?: number;
    perPage?: number;
    sort?: SupplierSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    active?: 'active' | 'inactive';
    enabled?: boolean;
};

export function useSuppliers(params: SuppliersListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'name', direction = 'asc', q = '', active, enabled = true } = params;

    return useQuery({
        queryKey: [...purchasingRootKey(tenant?.id), 'suppliers', { page, perPage, sort, direction, q, active }] as const,
        queryFn: async (): Promise<SuppliersPage> => {
            const { data } = await apiClient.get('/suppliers', {
                params: { page, per_page: perPage, sort, direction, q: q || undefined, active: active === 'active' ? 1 : active === 'inactive' ? 0 : undefined },
            });
            return suppliersPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}

// Active suppliers for the picker in the PO builder (first 100 by name).
export function useSupplierOptions(enabled = true) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: [...purchasingRootKey(tenant?.id), 'supplier-options'] as const,
        queryFn: async (): Promise<Supplier[]> => {
            const { data } = await apiClient.get('/suppliers', { params: { active: 1, per_page: 100, sort: 'name', direction: 'asc' } });
            return suppliersPageSchema.parse(data).data;
        },
        enabled: enabled && !!tenant?.id,
    });
}

export type PurchaseOrdersListParams = {
    page?: number;
    perPage?: number;
    sort?: PoSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    status?: PoStatus;
    enabled?: boolean;
};

export function usePurchaseOrders(params: PurchaseOrdersListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'po_number', direction = 'desc', q = '', status, enabled = true } = params;

    return useQuery({
        queryKey: [...purchasingRootKey(tenant?.id), 'purchase-orders', { page, perPage, sort, direction, q, status }] as const,
        queryFn: async (): Promise<PurchaseOrdersPage> => {
            const { data } = await apiClient.get('/purchase-orders', {
                params: { page, per_page: perPage, sort, direction, q: q || undefined, status: status || undefined },
            });
            return purchaseOrdersPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}

// The list carries lines_count only; the detail and the builder need the lines, so they load the detail fresh.
export function usePurchaseOrder(poId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: purchaseOrderDetailKey(tenant?.id, poId ?? 0),
        queryFn: async (): Promise<PurchaseOrder> => {
            const { data } = await apiClient.get(`/purchase-orders/${poId}`);
            return purchaseOrderSchema.parse(data);
        },
        enabled: !!tenant?.id && poId !== null,
        staleTime: 0,
    });
}
'@

$usePurchasingMutationsTs = @'
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTenant } from '@/shared/hooks/useTenant';
import { inventoryRootKey } from '@/features/inventory/api/useInventory';
import {
    purchaseOrderSchema,
    supplierSchema,
    type PoFormValues,
    type PoStatus,
    type PurchaseOrder,
    type Supplier,
    type SupplierFormValues,
} from '../schemas/purchasing';
import { purchaseOrderDetailKey, purchasingRootKey } from './usePurchasing';

// First 422 message per field ({ errors: { 'lines.0.quantity': [..] } }). Empty for any other error.
export function purchasingFieldErrors(err: unknown): Record<string, string> {
    if (!isApiError(err) || err.response?.status !== 422) return {};
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') out[field] = messages[0];
    }
    return out;
}

export function isStale(err: unknown): boolean {
    return isApiError(err) && err.response?.status === 409;
}

// 409 carries the current PO ({ code: 'stale_purchase_order', current }): used to reconcile the cache.
function staleCurrent(err: unknown): PurchaseOrder | null {
    if (!isStale(err) || !isApiError(err)) return null;
    const parsed = purchaseOrderSchema.safeParse((err.response?.data as { current?: unknown } | undefined)?.current);
    return parsed.success ? parsed.data : null;
}

export function purchasingErrorMessage(err: unknown, fallback: string): string {
    if (isApiError(err) && err.response?.status === 409) return err.response.data.message;
    return apiErrorMessage(err, fallback);
}

export type SaveSupplierVars = { supplierId?: number; values: SupplierFormValues };

// NOT optimistic: the server owns uniqueness of the name.
export function useSaveSupplier() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ supplierId, values }: SaveSupplierVars): Promise<Supplier> => {
            const body = { name: values.name, email: values.email || null, phone: values.phone || null, notes: values.notes || null, is_active: values.is_active };
            const { data } = supplierId ? await apiClient.put(`/suppliers/${supplierId}`, body) : await apiClient.post('/suppliers', body);
            return supplierSchema.parse(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: purchasingRootKey(tenant?.id) }),
    });
}

export type SavePoVars = { poId?: number; lockVersion?: number; values: PoFormValues };

// NOT optimistic: totals and numbering are computed by the server. Editing sends the lock_version (409 when stale).
export function useSavePurchaseOrder() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ poId, lockVersion, values }: SavePoVars): Promise<PurchaseOrder> => {
            const body = {
                supplier_id: Number(values.supplier_id),
                currency: values.currency.toUpperCase(),
                expected_date: values.expected_date || null,
                notes: values.notes || null,
                lines: values.lines.map((l) => ({ inventory_item_id: Number(l.inventory_item_id), quantity: l.quantity.trim(), unit_cost: l.unit_cost.trim() })),
                ...(poId !== undefined && lockVersion !== undefined ? { lock_version: lockVersion } : {}),
            };
            const { data } = poId ? await apiClient.put(`/purchase-orders/${poId}`, body) : await apiClient.post('/purchase-orders', body);
            return purchaseOrderSchema.parse(data);
        },
        onSuccess: (po) => {
            queryClient.setQueryData(purchaseOrderDetailKey(tenant?.id, po.id), po);
            void queryClient.invalidateQueries({ queryKey: purchasingRootKey(tenant?.id) });
        },
    });
}

export type TransitionVars = { poId: number; toStatus: PoStatus; lockVersion: number; note?: string };

// State-machine pattern (3.0): optimistic status change, rolled back on failure. On a 409 the server's current PO
// replaces the optimistic copy instead of the stale snapshot.
export function useTransitionPurchaseOrder() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ poId, toStatus, lockVersion, note }: TransitionVars): Promise<PurchaseOrder> => {
            const { data } = await apiClient.post(`/purchase-orders/${poId}/transition`, {
                to_status: toStatus,
                lock_version: lockVersion,
                ...(note ? { note } : {}),
            });
            return purchaseOrderSchema.parse(data);
        },
        onMutate: async (vars) => {
            const key = purchaseOrderDetailKey(tenant?.id, vars.poId);
            await queryClient.cancelQueries({ queryKey: key });
            const previous = queryClient.getQueryData<PurchaseOrder>(key);
            if (previous) {
                queryClient.setQueryData<PurchaseOrder>(key, { ...previous, status: vars.toStatus, lock_version: previous.lock_version + 1 });
            }
            return { previous, key };
        },
        onError: (err, _vars, ctx) => {
            if (!ctx) return;
            const current = staleCurrent(err);
            if (current) queryClient.setQueryData(ctx.key, current);
            else if (ctx.previous) queryClient.setQueryData(ctx.key, ctx.previous);
        },
        onSuccess: (po, vars) => {
            queryClient.setQueryData(purchaseOrderDetailKey(tenant?.id, vars.poId), po);
        },
        onSettled: () => void queryClient.invalidateQueries({ queryKey: purchasingRootKey(tenant?.id) }),
    });
}

export type ReceiveVars = { poId: number; lockVersion: number; receipts: { line_id: number; quantity: string }[]; note?: string };

// NOT optimistic: receiving moves stock, and the server decides (remaining quantities, inactive items, status).
export function useReceivePurchaseOrder() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ poId, lockVersion, receipts, note }: ReceiveVars): Promise<PurchaseOrder> => {
            const { data } = await apiClient.post(`/purchase-orders/${poId}/receive`, { lock_version: lockVersion, receipts, ...(note ? { note } : {}) });
            return purchaseOrderSchema.parse(data);
        },
        onSuccess: (po) => {
            queryClient.setQueryData(purchaseOrderDetailKey(tenant?.id, po.id), po);
            void queryClient.invalidateQueries({ queryKey: purchasingRootKey(tenant?.id) });
            void queryClient.invalidateQueries({ queryKey: inventoryRootKey(tenant?.id) });
        },
        onError: (err, vars) => {
            const current = staleCurrent(err);
            if (current) queryClient.setQueryData(purchaseOrderDetailKey(tenant?.id, vars.poId), current);
        },
    });
}
'@

$supplierModalTsx = @'
import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { purchasingErrorMessage, purchasingFieldErrors, useSaveSupplier } from '../api/usePurchasingMutations';
import { supplierFormSchema, type Supplier, type SupplierFormValues } from '../schemas/purchasing';

const FIELDS = ['name', 'email', 'phone', 'notes'] as const;

function blank(s?: Supplier | null): SupplierFormValues {
    return s
        ? { name: s.name, email: s.email ?? '', phone: s.phone ?? '', notes: s.notes ?? '', is_active: s.is_active }
        : { name: '', email: '', phone: '', notes: '', is_active: true };
}

// 11.2c: create / edit a supplier.
export function SupplierFormModal({ open, onClose, supplier }: { open: boolean; onClose: () => void; supplier?: Supplier | null }) {
    const save = useSaveSupplier();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<SupplierFormValues>({ resolver: zodResolver(supplierFormSchema), defaultValues: blank(supplier) });
    const editing = !!supplier;

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset(blank(supplier));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, supplier?.id]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            await save.mutateAsync({ supplierId: supplier?.id, values });
            onClose();
        } catch (err) {
            const fields = purchasingFieldErrors(err);
            let shown = false;
            for (const f of FIELDS) {
                if (fields[f]) {
                    form.setError(f, { message: fields[f] });
                    shown = true;
                }
            }
            if (!shown) setFormError(purchasingErrorMessage(err, 'Could not save the supplier. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title={editing ? 'Edit supplier' : 'New supplier'}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <div>
                    <label htmlFor="sup-name" className="mb-1 block text-sm font-medium">
                        Name
                    </label>
                    <input id="sup-name" aria-invalid={errors.name ? true : undefined} className={inputClass} {...form.register('name')} />
                    {errors.name?.message && (
                        <p role="alert" className={errClass}>
                            {errors.name.message}
                        </p>
                    )}
                </div>
                <div className="grid grid-cols-2 gap-3">
                    <div>
                        <label htmlFor="sup-email" className="mb-1 block text-sm font-medium">
                            Email
                        </label>
                        <input id="sup-email" type="email" aria-invalid={errors.email ? true : undefined} className={inputClass} {...form.register('email')} />
                        {errors.email?.message && (
                            <p role="alert" className={errClass}>
                                {errors.email.message}
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="sup-phone" className="mb-1 block text-sm font-medium">
                            Phone
                        </label>
                        <input id="sup-phone" aria-invalid={errors.phone ? true : undefined} className={inputClass} {...form.register('phone')} />
                        {errors.phone?.message && (
                            <p role="alert" className={errClass}>
                                {errors.phone.message}
                            </p>
                        )}
                    </div>
                </div>
                <div>
                    <label htmlFor="sup-notes" className="mb-1 block text-sm font-medium">
                        Notes
                    </label>
                    <textarea id="sup-notes" rows={3} className={inputClass} {...form.register('notes')} />
                    {errors.notes?.message && (
                        <p role="alert" className={errClass}>
                            {errors.notes.message}
                        </p>
                    )}
                </div>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" {...form.register('is_active')} />
                    Active (inactive suppliers cannot take new purchase orders)
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
                        {form.formState.isSubmitting ? 'Saving...' : editing ? 'Save changes' : 'Create supplier'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
'@

$suppliersPageTsx = @'
import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useSuppliers } from '../api/usePurchasing';
import { validateSuppliersSearch, type Supplier, type SupplierSortColumn, type SuppliersSearch } from '../schemas/purchasing';
import { SupplierFormModal } from './SupplierFormModal';

const columnHelper = createColumnHelper<Supplier>();
const EMPTY: Supplier[] = [];

export function SuppliersPage() {
    const allowed = usePermission('inventory.view');
    const canManage = usePermission('inventory.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateSuppliersSearch(raw);

    const page = search.page ?? 1;
    const sort: SupplierSortColumn = search.sort ?? 'name';
    const direction = search.direction ?? 'asc';

    function setSearch(patch: Partial<SuppliersSearch>) {
        void navigate({ to: '/suppliers', search: validateSuppliersSearch({ ...search, ...patch }), replace: true });
    }

    const [editing, setEditing] = useState<Supplier | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useSuppliers({ page, sort, direction, q: search.q, active: search.active, enabled: allowed });

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('name', { header: 'Name', cell: (info) => <span className="font-medium">{info.getValue()}</span> }),
            columnHelper.accessor('email', { header: 'Email', cell: (info) => info.getValue() ?? '-', enableSorting: false }),
            columnHelper.accessor('phone', { header: 'Phone', cell: (info) => info.getValue() ?? '-', enableSorting: false }),
            columnHelper.accessor('is_active', {
                header: 'Status',
                cell: (info) => <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{info.getValue() ? 'Active' : 'Inactive'}</span>,
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) =>
                    canManage ? (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(row.original);
                                setFormOpen(true);
                            }}
                            className="rounded-md border px-2 py-1 text-xs"
                        >
                            Edit
                        </button>
                    ) : null,
                enableSorting: false,
            }),
        ],
        [canManage],
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
            setSearch({ sort: first.id as SupplierSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to suppliers.</p>;

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.active);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">Suppliers</h1>
                    <Link to="/purchase-orders" className="text-sm underline">
                        Purchase orders
                    </Link>
                </div>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(null);
                                setFormOpen(true);
                            }}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90"
                        >
                            New supplier
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input type="search" placeholder="Search name or email..." value={qInput} onChange={(e) => setQInput(e.target.value)} className="w-64 rounded-md border px-3 py-2 text-sm" />
                <select
                    aria-label="Filter by status"
                    value={search.active ?? ''}
                    onChange={(e) => setSearch({ active: (e.target.value || undefined) as SuppliersSearch['active'], page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All suppliers</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/suppliers', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load suppliers.")}
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
                                    {hasFilters ? 'No suppliers match these filters.' : 'No suppliers yet.'}
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

            <SupplierFormModal open={formOpen} onClose={() => setFormOpen(false)} supplier={editing} />
        </div>
    );
}
'@

$poPageTsx = @'
import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { usePurchaseOrders } from '../api/usePurchasing';
import {
    PO_STATUSES,
    PO_STATUS_CLASSES,
    PO_STATUS_LABELS,
    validatePurchaseOrdersSearch,
    type PoSortColumn,
    type PoStatus,
    type PurchaseOrder,
    type PurchaseOrdersSearch,
} from '../schemas/purchasing';

const columnHelper = createColumnHelper<PurchaseOrder>();
const EMPTY: PurchaseOrder[] = [];

export function PurchaseOrdersPage() {
    const allowed = usePermission('inventory.view');
    const canManage = usePermission('inventory.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validatePurchaseOrdersSearch(raw);

    const page = search.page ?? 1;
    const sort: PoSortColumn = search.sort ?? 'po_number';
    const direction = search.direction ?? 'desc';

    function setSearch(patch: Partial<PurchaseOrdersSearch>) {
        void navigate({ to: '/purchase-orders', search: validatePurchaseOrdersSearch({ ...search, ...patch }), replace: true });
    }

    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = usePurchaseOrders({ page, sort, direction, q: search.q, status: search.status, enabled: allowed });

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('po_number', {
                header: 'Number',
                cell: (info) => (
                    <Link to="/purchase-orders/$poId" params={{ poId: String(info.row.original.id) }} className="font-mono text-xs underline">
                        {info.getValue()}
                    </Link>
                ),
            }),
            columnHelper.display({
                id: 'supplier',
                header: 'Supplier',
                cell: ({ row }) => <span className="font-medium">{row.original.supplier?.name ?? '-'}</span>,
                enableSorting: false,
            }),
            columnHelper.accessor('status', {
                header: 'Status',
                cell: (info) => <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${PO_STATUS_CLASSES[info.getValue()]}`}>{PO_STATUS_LABELS[info.getValue()]}</span>,
            }),
            columnHelper.accessor('expected_date', { header: 'Expected', cell: (info) => info.getValue() ?? '-' }),
            columnHelper.accessor('subtotal', { header: 'Total', cell: (info) => formatMoney(info.getValue(), info.row.original.currency) }),
            columnHelper.accessor('lines_count', { header: 'Lines', cell: (info) => info.getValue() ?? 0, enableSorting: false }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => (
                    <Link to="/purchase-orders/$poId" params={{ poId: String(row.original.id) }} className="rounded-md border px-2 py-1 text-xs">
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
            setSearch({ sort: first.id as PoSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to purchase orders.</p>;

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.status);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">Purchase orders</h1>
                    <Link to="/inventory" className="text-sm underline">
                        Inventory
                    </Link>
                    <Link to="/suppliers" className="text-sm underline">
                        Suppliers
                    </Link>
                </div>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <Link to="/purchase-orders/new" className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                            New purchase order
                        </Link>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input type="search" placeholder="Search number or supplier..." value={qInput} onChange={(e) => setQInput(e.target.value)} className="w-64 rounded-md border px-3 py-2 text-sm" />
                <select
                    aria-label="Filter by status"
                    value={search.status ?? ''}
                    onChange={(e) => setSearch({ status: (e.target.value || undefined) as PoStatus | undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All statuses</option>
                    {PO_STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {PO_STATUS_LABELS[s]}
                        </option>
                    ))}
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/purchase-orders', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load purchase orders.")}
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
                                    {hasFilters ? 'No purchase orders match these filters.' : 'No purchase orders yet.'}
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
        </div>
    );
}
'@

$builderTsx = @'
import { useEffect, useRef, useState } from 'react';
import { useFieldArray, useForm, useWatch, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { useInventoryItems } from '@/features/inventory/api/useInventory';
import { useSupplierOptions, usePurchaseOrder } from '../api/usePurchasing';
import { isStale, purchasingFieldErrors, useSavePurchaseOrder } from '../api/usePurchasingMutations';
import { emptyPoLine, estimateLineTotal, poFormSchema, type PoFormValues, type PurchaseOrder } from '../schemas/purchasing';

const HEADER_FIELDS = ['supplier_id', 'currency', 'expected_date', 'notes'] as const;
const LINE_FIELD_RE = /^lines\.(\d+)\.(inventory_item_id|quantity|unit_cost)$/;

function blankPo(): PoFormValues {
    return { supplier_id: '', currency: 'USD', expected_date: '', notes: '', lines: [emptyPoLine()] };
}

function toFormValues(po: PurchaseOrder): PoFormValues {
    const lines = (po.lines ?? []).map((l) => ({ inventory_item_id: String(l.inventory_item_id), quantity: l.quantity, unit_cost: l.unit_cost }));
    return {
        supplier_id: String(po.supplier_id),
        currency: po.currency,
        expected_date: po.expected_date ?? '',
        notes: po.notes ?? '',
        lines: lines.length > 0 ? lines : [emptyPoLine()],
    };
}

// 11.2c: purchase order builder (create, or edit a draft). useFieldArray line items; the total shown is an estimate,
// the server computes the real one. Editing sends the lock_version (409 when someone else changed the PO).
export function PurchaseOrderBuilder() {
    const canManage = usePermission('inventory.manage');
    const navigate = useNavigate();
    const params = useParams({ strict: false }) as { poId?: string };
    const editId = params.poId !== undefined ? Number(params.poId) : null;
    const validEdit = editId !== null && Number.isInteger(editId) && editId > 0;
    const editing = params.poId !== undefined;

    const poQuery = usePurchaseOrder(validEdit ? editId : null);
    const suppliers = useSupplierOptions(canManage);
    const items = useInventoryItems({ perPage: 100, active: 'active', sort: 'sku', enabled: canManage });
    const save = useSavePurchaseOrder();
    const [formError, setFormError] = useState<string | null>(null);

    const form = useForm<PoFormValues>({ resolver: zodResolver(poFormSchema), defaultValues: blankPo() });
    const { fields, append, remove } = useFieldArray({ control: form.control, name: 'lines' });
    const watchedLines = useWatch({ control: form.control, name: 'lines' });
    const currency = useWatch({ control: form.control, name: 'currency' });

    const resetFor = useRef<number | null>(null);
    const po = poQuery.data;
    useEffect(() => {
        if (po && resetFor.current !== po.id) {
            resetFor.current = po.id;
            form.reset(toFormValues(po));
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [po]);

    if (!canManage) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to edit purchase orders.</p>;
    if (editing && !validEdit) return <p className="text-sm text-red-700">Invalid purchase order.</p>;
    if (editing && poQuery.isPending) return <p className="text-sm text-gray-500">Loading...</p>;
    if (editing && (poQuery.isError || !po)) {
        return (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {apiErrorMessage(poQuery.error, "Couldn't load this purchase order.")}
            </p>
        );
    }
    if (po && po.status !== 'draft') {
        return (
            <p className="text-sm text-[var(--color-ink-muted)]">
                Only draft purchase orders can be edited.{' '}
                <Link to="/purchase-orders/$poId" params={{ poId: String(po.id) }} className="underline">
                    Back to {po.po_number}
                </Link>
            </p>
        );
    }

    // Pickers: active suppliers/items, plus whatever the PO being edited already points at.
    const supplierOptions = [...(suppliers.data ?? []).map((s) => ({ id: String(s.id), name: s.name }))];
    if (po?.supplier && !supplierOptions.some((s) => s.id === String(po.supplier?.id))) supplierOptions.push({ id: String(po.supplier.id), name: po.supplier.name });

    const itemOptions = (items.data?.data ?? []).map((i) => ({ id: String(i.id), label: `${i.sku} - ${i.name}`, unit: i.unit }));
    for (const l of po?.lines ?? []) {
        if (!itemOptions.some((o) => o.id === String(l.inventory_item_id))) {
            itemOptions.push({ id: String(l.inventory_item_id), label: `${l.sku ?? '?'} - ${l.name ?? ''}`, unit: l.unit ?? '' });
        }
    }
    const unitOf = (id: string) => itemOptions.find((o) => o.id === id)?.unit ?? '';

    const lineTotals = (watchedLines ?? []).map((l) => estimateLineTotal(l?.quantity ?? '', l?.unit_cost ?? ''));
    const subtotal = lineTotals.reduce<number>((sum, t) => sum + (t ?? 0), 0);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        try {
            const saved = await save.mutateAsync({ poId: po?.id, lockVersion: po?.lock_version, values });
            void navigate({ to: '/purchase-orders/$poId', params: { poId: String(saved.id) } });
        } catch (err) {
            if (isStale(err)) {
                setFormError('This purchase order was updated by someone else. Open it to see the latest version, then edit again.');
                return;
            }
            const fields422 = purchasingFieldErrors(err);
            let shown = false;
            for (const [key, message] of Object.entries(fields422)) {
                if ((HEADER_FIELDS as readonly string[]).includes(key) || LINE_FIELD_RE.test(key)) {
                    form.setError(key as Path<PoFormValues>, { message });
                    shown = true;
                }
            }
            if (!shown) setFormError(fields422.lines ?? fields422.po ?? fields422.lock_version ?? apiErrorMessage(err, 'Could not save the purchase order. Please try again.'));
        }
    });

    const inputClass = 'w-full rounded-md border px-3 py-2 text-sm';
    const errClass = 'mt-1 text-xs text-red-700';
    const errors = form.formState.errors;
    const money = (n: number) => formatMoney(n.toFixed(2), currency || 'USD');

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/purchase-orders" className="text-[var(--color-ink-muted)] underline">
                    Purchase orders
                </Link>
            </div>
            <h1 className="mb-4 text-lg font-medium text-[var(--color-ink)]">{po ? `Edit ${po.po_number}` : 'New purchase order'}</h1>

            <form onSubmit={onSubmit} noValidate className="flex max-w-4xl flex-col gap-4">
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div className="sm:col-span-2">
                        <label htmlFor="po-supplier" className="mb-1 block text-sm font-medium">
                            Supplier
                        </label>
                        <select id="po-supplier" aria-invalid={errors.supplier_id ? true : undefined} className={inputClass} {...form.register('supplier_id')}>
                            <option value="">Choose a supplier...</option>
                            {supplierOptions.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                </option>
                            ))}
                        </select>
                        {errors.supplier_id?.message && (
                            <p role="alert" className={errClass}>
                                {errors.supplier_id.message}
                            </p>
                        )}
                        {suppliers.isSuccess && supplierOptions.length === 0 && (
                            <p className="mt-1 text-xs text-[var(--color-ink-muted)]">
                                No active suppliers yet.{' '}
                                <Link to="/suppliers" className="underline">
                                    Add one
                                </Link>
                                .
                            </p>
                        )}
                    </div>
                    <div>
                        <label htmlFor="po-currency" className="mb-1 block text-sm font-medium">
                            Currency
                        </label>
                        <input id="po-currency" maxLength={3} aria-invalid={errors.currency ? true : undefined} className={inputClass} {...form.register('currency')} />
                        {errors.currency?.message && (
                            <p role="alert" className={errClass}>
                                {errors.currency.message}
                            </p>
                        )}
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div>
                        <label htmlFor="po-expected" className="mb-1 block text-sm font-medium">
                            Expected date
                        </label>
                        <input id="po-expected" type="date" aria-invalid={errors.expected_date ? true : undefined} className={inputClass} {...form.register('expected_date')} />
                        {errors.expected_date?.message && (
                            <p role="alert" className={errClass}>
                                {errors.expected_date.message}
                            </p>
                        )}
                    </div>
                    <div className="sm:col-span-2">
                        <label htmlFor="po-notes" className="mb-1 block text-sm font-medium">
                            Notes
                        </label>
                        <input id="po-notes" aria-invalid={errors.notes ? true : undefined} className={inputClass} {...form.register('notes')} />
                        {errors.notes?.message && (
                            <p role="alert" className={errClass}>
                                {errors.notes.message}
                            </p>
                        )}
                    </div>
                </div>

                <section>
                    <div className="mb-2 flex items-center justify-between">
                        <h2 className="text-base font-medium text-[var(--color-ink)]">Lines</h2>
                        <button type="button" onClick={() => append(emptyPoLine())} className="rounded-md border px-3 py-1.5 text-sm">
                            Add line
                        </button>
                    </div>
                    {items.isSuccess && itemOptions.length === 0 && (
                        <p className="mb-2 text-xs text-[var(--color-ink-muted)]">
                            No active inventory items yet.{' '}
                            <Link to="/inventory" className="underline">
                                Add one
                            </Link>
                            .
                        </p>
                    )}
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-sm">
                            <thead>
                                <tr>
                                    <th className="border-b px-2 py-2 text-left font-medium">Item</th>
                                    <th className="border-b px-2 py-2 text-left font-medium">Quantity</th>
                                    <th className="border-b px-2 py-2 text-left font-medium">Unit cost</th>
                                    <th className="border-b px-2 py-2 text-right font-medium">Line total</th>
                                    <th className="border-b px-2 py-2" />
                                </tr>
                            </thead>
                            <tbody>
                                {fields.map((field, index) => {
                                    const rowErrors = errors.lines?.[index];
                                    const itemId = watchedLines?.[index]?.inventory_item_id ?? '';
                                    const total = lineTotals[index];
                                    return (
                                        <tr key={field.id}>
                                            <td className="border-b px-2 py-2 align-top">
                                                <select aria-label={`Item for line ${index + 1}`} aria-invalid={rowErrors?.inventory_item_id ? true : undefined} className={inputClass} {...form.register(`lines.${index}.inventory_item_id`)}>
                                                    <option value="">Choose an item...</option>
                                                    {itemOptions.map((o) => (
                                                        <option key={o.id} value={o.id}>
                                                            {o.label}
                                                        </option>
                                                    ))}
                                                </select>
                                                {rowErrors?.inventory_item_id?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.inventory_item_id.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 align-top">
                                                <div className="flex items-center gap-1">
                                                    <input aria-label={`Quantity for line ${index + 1}`} inputMode="decimal" aria-invalid={rowErrors?.quantity ? true : undefined} className="w-24 rounded-md border px-3 py-2 text-sm" {...form.register(`lines.${index}.quantity`)} />
                                                    <span className="text-xs text-[var(--color-ink-muted)]">{unitOf(itemId)}</span>
                                                </div>
                                                {rowErrors?.quantity?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.quantity.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 align-top">
                                                <input aria-label={`Unit cost for line ${index + 1}`} inputMode="decimal" aria-invalid={rowErrors?.unit_cost ? true : undefined} className="w-28 rounded-md border px-3 py-2 text-sm" {...form.register(`lines.${index}.unit_cost`)} />
                                                {rowErrors?.unit_cost?.message && (
                                                    <p role="alert" className={errClass}>
                                                        {rowErrors.unit_cost.message}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="border-b px-2 py-2 text-right align-top">{total === null || total === undefined ? '-' : money(total)}</td>
                                            <td className="border-b px-2 py-2 text-right align-top">
                                                <button type="button" onClick={() => remove(index)} disabled={fields.length <= 1} className="rounded-md border px-2 py-1 text-xs text-red-700 disabled:opacity-40">
                                                    Remove
                                                </button>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <dl className="mt-3 ml-auto grid w-64 grid-cols-2 gap-y-1 text-sm">
                        <dt className="font-medium">Estimated total</dt>
                        <dd className="text-right font-medium">{money(subtotal)}</dd>
                    </dl>
                    <p className="text-right text-xs text-[var(--color-ink-muted)]">The server computes the final total when you save.</p>
                </section>

                {formError && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {formError}
                    </p>
                )}

                <div className="flex justify-end gap-2">
                    <Link to={po ? '/purchase-orders/$poId' : '/purchase-orders'} params={po ? { poId: String(po.id) } : undefined} className="rounded-md border px-3 py-1.5 text-sm">
                        Cancel
                    </Link>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Saving...' : po ? 'Save changes' : 'Create purchase order'}
                    </button>
                </div>
            </form>
        </div>
    );
}
'@

$detailTsx = @'
import { useEffect, useState } from 'react';
import { useFieldArray, useForm, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useParams } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { formatQty } from '@/features/inventory/schemas/inventory';
import { usePurchaseOrder } from '../api/usePurchasing';
import { purchasingErrorMessage, purchasingFieldErrors, useReceivePurchaseOrder, useTransitionPurchaseOrder } from '../api/usePurchasingMutations';
import {
    PO_STATUS_CLASSES,
    PO_STATUS_LABELS,
    PO_TRANSITIONS,
    cancelFormSchema,
    receiveFormSchema,
    type CancelFormValues,
    type PoStatus,
    type PurchaseOrder,
    type ReceiveFormValues,
} from '../schemas/purchasing';

const RECEIPT_ERROR_RE = /^receipts\.(\d+)\.(quantity|line_id)$/;

function receiveRows(po: PurchaseOrder): ReceiveFormValues['rows'] {
    return (po.lines ?? [])
        .filter((l) => Number(l.remaining) > 0)
        .map((l) => ({ line_id: l.id, label: `${l.sku ?? '?'} - ${l.name ?? ''}`, unit: l.unit ?? '', remaining: l.remaining, quantity: l.remaining }));
}

// Receive goods: one row per line that still has something to receive, prefilled with the remaining quantity.
// Not optimistic: the server writes the ledger receipts and decides the new status.
function ReceiveModal({ open, onClose, po }: { open: boolean; onClose: () => void; po: PurchaseOrder }) {
    const receive = useReceivePurchaseOrder();
    const [formError, setFormError] = useState<string | null>(null);
    const form = useForm<ReceiveFormValues>({ resolver: zodResolver(receiveFormSchema), defaultValues: { note: '', rows: receiveRows(po) } });
    const { fields } = useFieldArray({ control: form.control, name: 'rows' });

    useEffect(() => {
        if (open) {
            setFormError(null);
            form.reset({ note: '', rows: receiveRows(po) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onSubmit = form.handleSubmit(async (values) => {
        setFormError(null);
        const rowIndexes: number[] = [];
        const receipts: { line_id: number; quantity: string }[] = [];
        values.rows.forEach((r, i) => {
            if (r.quantity !== '') {
                rowIndexes.push(i);
                receipts.push({ line_id: r.line_id, quantity: r.quantity });
            }
        });

        try {
            await receive.mutateAsync({ poId: po.id, lockVersion: po.lock_version, receipts, note: values.note });
            onClose();
        } catch (err) {
            const fields422 = purchasingFieldErrors(err);
            let shown = false;
            for (const [key, message] of Object.entries(fields422)) {
                const m = RECEIPT_ERROR_RE.exec(key);
                const row = m ? rowIndexes[Number(m[1])] : undefined;
                if (row !== undefined) {
                    form.setError(`rows.${row}.quantity` as Path<ReceiveFormValues>, { message });
                    shown = true;
                }
            }
            if (!shown) setFormError(fields422.lines ?? fields422.po ?? fields422.receipts ?? purchasingErrorMessage(err, 'Could not receive the goods. Please try again.'));
        }
    });

    const errors = form.formState.errors;

    return (
        <Modal open={open} onClose={onClose} title={`Receive goods - ${po.po_number}`}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <p className="text-sm text-[var(--color-ink-muted)]">Enter what arrived. Leave a line empty to receive nothing for it. Stock is updated as a ledger receipt referencing {po.po_number}.</p>
                {fields.map((field, index) => (
                    <div key={field.id}>
                        <label htmlFor={`recv-${index}`} className="mb-1 block text-sm font-medium">
                            {field.label} <span className="font-normal text-[var(--color-ink-muted)]">(remaining {formatQty(field.remaining)} {field.unit})</span>
                        </label>
                        <input id={`recv-${index}`} inputMode="decimal" aria-invalid={errors.rows?.[index]?.quantity ? true : undefined} className="w-full rounded-md border px-3 py-2 text-sm" {...form.register(`rows.${index}.quantity`)} />
                        {errors.rows?.[index]?.quantity?.message && (
                            <p role="alert" className="mt-1 text-xs text-red-700">
                                {errors.rows[index]?.quantity?.message}
                            </p>
                        )}
                    </div>
                ))}
                <div>
                    <label htmlFor="recv-note" className="mb-1 block text-sm font-medium">
                        Note
                    </label>
                    <textarea id="recv-note" rows={2} className="w-full rounded-md border px-3 py-2 text-sm" {...form.register('note')} />
                    {errors.note?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
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
                    <button type="submit" disabled={form.formState.isSubmitting || fields.length === 0} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Receiving...' : 'Receive goods'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

function CancelModal({ open, onClose, onConfirm }: { open: boolean; onClose: () => void; onConfirm: (reason: string) => Promise<boolean> }) {
    const form = useForm<CancelFormValues>({ resolver: zodResolver(cancelFormSchema), defaultValues: { reason: '' } });

    useEffect(() => {
        if (open) form.reset({ reason: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onSubmit = form.handleSubmit(async (values) => {
        if (await onConfirm(values.reason)) onClose();
    });

    return (
        <Modal open={open} onClose={onClose} title="Cancel purchase order">
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <div>
                    <label htmlFor="po-cancel-reason" className="mb-1 block text-sm font-medium">
                        Reason
                    </label>
                    <textarea id="po-cancel-reason" rows={4} aria-invalid={form.formState.errors.reason ? true : undefined} className="w-full rounded-md border px-3 py-2 text-sm" {...form.register('reason')} />
                    {form.formState.errors.reason?.message && (
                        <p role="alert" className="mt-1 text-xs text-red-700">
                            {form.formState.errors.reason.message}
                        </p>
                    )}
                </div>
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} disabled={form.formState.isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                        Keep it
                    </button>
                    <button type="submit" disabled={form.formState.isSubmitting} className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {form.formState.isSubmitting ? 'Cancelling...' : 'Cancel purchase order'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

// 11.2c: PO detail. Status buttons follow the 3.0 pattern (optimistic, 409 -> reconcile to the server's copy);
// "Receive goods" is its own mutation because it moves stock.
export function PurchaseOrderDetail() {
    const allowed = usePermission('inventory.view');
    const canManage = usePermission('inventory.manage');
    const params = useParams({ strict: false }) as { poId?: string };
    const poId = Number(params.poId);
    const validId = Number.isInteger(poId) && poId > 0;

    const query = usePurchaseOrder(validId && allowed ? poId : null);
    const transition = useTransitionPurchaseOrder();
    const [cancelOpen, setCancelOpen] = useState(false);
    const [receiveOpen, setReceiveOpen] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to purchase orders.</p>;
    if (!validId) return <p className="text-sm text-red-700">Invalid purchase order.</p>;
    if (query.isPending) return <p className="text-sm text-gray-500">Loading...</p>;
    if (query.isError || !query.data) {
        return (
            <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                {apiErrorMessage(query.error, "Couldn't load this purchase order.")}
            </p>
        );
    }

    const po = query.data;
    const lines = po.lines ?? [];
    const money = (v: string) => formatMoney(v, po.currency);
    const canReceive = canManage && (po.status === 'ordered' || po.status === 'partially_received');

    async function go(toStatus: PoStatus, note?: string): Promise<boolean> {
        setActionError(null);
        try {
            await transition.mutateAsync({ poId: po.id, toStatus, lockVersion: po.lock_version, note });
            return true;
        } catch (err) {
            setActionError(purchasingErrorMessage(err, 'Could not update the purchase order.'));
            return false;
        }
    }

    const nextStatuses = PO_TRANSITIONS[po.status];

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/purchase-orders" className="text-[var(--color-ink-muted)] underline">
                    Purchase orders
                </Link>
            </div>
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                <h1 className="flex items-center gap-3 text-lg font-medium text-[var(--color-ink)]">
                    <span className="font-mono text-base">{po.po_number}</span>
                    <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${PO_STATUS_CLASSES[po.status]}`}>{PO_STATUS_LABELS[po.status]}</span>
                </h1>
                {canManage && (
                    <div className="flex flex-wrap gap-2">
                        {po.status === 'draft' && (
                            <Link to="/purchase-orders/$poId/edit" params={{ poId: String(po.id) }} className="rounded-md border px-3 py-1.5 text-sm">
                                Edit
                            </Link>
                        )}
                        {nextStatuses.includes('ordered') && (
                            <button type="button" onClick={() => void go('ordered')} disabled={transition.isPending} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50">
                                Mark as ordered
                            </button>
                        )}
                        {canReceive && (
                            <button type="button" onClick={() => setReceiveOpen(true)} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                                Receive goods
                            </button>
                        )}
                        {nextStatuses.includes('cancelled') && (
                            <button type="button" onClick={() => setCancelOpen(true)} disabled={transition.isPending} className="rounded-md border px-3 py-1.5 text-sm text-red-700 disabled:opacity-50">
                                Cancel PO
                            </button>
                        )}
                    </div>
                )}
            </div>

            {actionError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {actionError}
                </p>
            )}

            <dl className="mb-6 grid max-w-3xl grid-cols-2 gap-x-6 gap-y-2 text-sm sm:grid-cols-4">
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Supplier</dt>
                    <dd className="font-medium">{po.supplier?.name ?? `#${po.supplier_id}`}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Expected</dt>
                    <dd>{po.expected_date ?? '-'}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Ordered</dt>
                    <dd>{po.ordered_at ? new Date(po.ordered_at).toLocaleString() : '-'}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Received</dt>
                    <dd>{po.received_at ? new Date(po.received_at).toLocaleString() : '-'}</dd>
                </div>
                {po.notes && (
                    <div className="col-span-2 sm:col-span-4">
                        <dt className="text-[var(--color-ink-muted)]">Notes</dt>
                        <dd>{po.notes}</dd>
                    </div>
                )}
                {po.cancel_reason && (
                    <div className="col-span-2 sm:col-span-4">
                        <dt className="text-[var(--color-ink-muted)]">Cancelled because</dt>
                        <dd>{po.cancel_reason}</dd>
                    </div>
                )}
            </dl>

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th className="border-b px-3 py-2 text-left font-medium">Item</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Ordered</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Received</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Remaining</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Unit cost</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.id}>
                                <td className="border-b px-3 py-2 align-top">
                                    <Link to="/inventory/$itemId" params={{ itemId: String(l.inventory_item_id) }} className="font-mono text-xs underline">
                                        {l.sku ?? '?'}
                                    </Link>
                                    <div className="text-xs text-[var(--color-ink-muted)]">{l.name}</div>
                                </td>
                                <td className="border-b px-3 py-2 text-right align-top">
                                    {formatQty(l.quantity)} {l.unit}
                                </td>
                                <td className="border-b px-3 py-2 text-right align-top">{formatQty(l.received_quantity)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{formatQty(l.remaining)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.unit_cost)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.line_total)}</td>
                            </tr>
                        ))}
                        {lines.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                                    No lines.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <dl className="mt-3 ml-auto grid w-64 grid-cols-2 gap-y-1 text-sm">
                <dt className="font-medium">Total</dt>
                <dd className="text-right font-medium">{money(po.subtotal)}</dd>
            </dl>

            <CancelModal open={cancelOpen} onClose={() => setCancelOpen(false)} onConfirm={(reason) => go('cancelled', reason)} />
            <ReceiveModal open={receiveOpen} onClose={() => setReceiveOpen(false)} po={po} />
        </div>
    );
}
'@

$routeBlock = @'
// 11.2c: suppliers and purchase orders. Lists keep filters/sort/page in the URL (8.7); "new" is a literal path, so it wins over $poId.
const suppliersRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/suppliers',
    validateSearch: validateSuppliersSearch,
    component: SuppliersPage,
});

const purchaseOrdersRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/purchase-orders',
    validateSearch: validatePurchaseOrdersSearch,
    component: PurchaseOrdersPage,
});

const purchaseOrderNewRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/purchase-orders/new',
    component: PurchaseOrderBuilder,
});

const purchaseOrderDetailRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/purchase-orders/$poId',
    component: PurchaseOrderDetail,
});

const purchaseOrderEditRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/purchase-orders/$poId/edit',
    component: PurchaseOrderBuilder,
});

'@

$invHeaderRepl = @'
<div className="flex items-center gap-4">
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">Inventory</h1>
                    <Link to="/purchase-orders" className="text-sm underline">
                        Purchase orders
                    </Link>
                    <Link to="/suppliers" className="text-sm underline">
                        Suppliers
                    </Link>
                </div>
'@
$invHeaderRepl = $invHeaderRepl.TrimEnd("`r", "`n")

# ---------------------------------------------------------------- patches (preflight on ORIGINAL files)
$patches = New-Object System.Collections.ArrayList

$importAnchor = "import { InventoryPage } from '@/features/inventory/components/InventoryPage';"
$importRepl = $importAnchor + "`n" +
    "import { SuppliersPage } from '@/features/purchasing/components/SuppliersPage';" + "`n" +
    "import { PurchaseOrdersPage } from '@/features/purchasing/components/PurchaseOrdersPage';" + "`n" +
    "import { PurchaseOrderBuilder } from '@/features/purchasing/components/PurchaseOrderBuilder';" + "`n" +
    "import { PurchaseOrderDetail } from '@/features/purchasing/components/PurchaseOrderDetail';" + "`n" +
    "import { validatePurchaseOrdersSearch, validateSuppliersSearch } from '@/features/purchasing/schemas/purchasing';"
[void]$patches.Add(@{ Path = $routerPath; Anchor = $importAnchor; Replacement = $importRepl; Skip = 'features/purchasing/components/SuppliersPage' })

$treeAnchor = 'const routeTree = rootRoute.addChildren(['
[void]$patches.Add(@{ Path = $routerPath; Anchor = $treeAnchor; Replacement = ($routeBlock + $treeAnchor); Skip = 'const purchaseOrdersRoute = createRoute' })

$childAnchor = 'inventoryRoute, inventoryDetailRoute]'
[void]$patches.Add(@{ Path = $routerPath; Anchor = $childAnchor; Replacement = 'inventoryRoute, inventoryDetailRoute, suppliersRoute, purchaseOrdersRoute, purchaseOrderNewRoute, purchaseOrderDetailRoute, purchaseOrderEditRoute]'; Skip = 'suppliersRoute, purchaseOrdersRoute' })

$h1Anchor = '<h1 className="text-lg font-medium text-[var(--color-ink)]">Inventory</h1>'
[void]$patches.Add(@{ Path = $invPagePath; Anchor = $h1Anchor; Replacement = $invHeaderRepl; Skip = 'to="/purchase-orders"' })

foreach ($p in $patches) {
    $t = (Read-Text $p.Path).Replace("`r`n", "`n")
    if ($t.Contains($p.Skip)) { continue }
    $c = Count-Sub $t $p.Anchor
    if ($c -ne 1) { throw "PREFLIGHT: anchor occurs $c times (need 1) in $($p.Path): $($p.Anchor)" }
}

# ---------------------------------------------------------------- write
$base = 'frontend\src\features\purchasing'
Write-NoBom "$base\schemas\purchasing.ts" $schemaTs
Write-NoBom "$base\api\usePurchasing.ts" $usePurchasingTs
Write-NoBom "$base\api\usePurchasingMutations.ts" $usePurchasingMutationsTs
Write-NoBom "$base\components\SupplierFormModal.tsx" $supplierModalTsx
Write-NoBom "$base\components\SuppliersPage.tsx" $suppliersPageTsx
Write-NoBom "$base\components\PurchaseOrdersPage.tsx" $poPageTsx
Write-NoBom "$base\components\PurchaseOrderBuilder.tsx" $builderTsx
Write-NoBom "$base\components\PurchaseOrderDetail.tsx" $detailTsx
Say 'Wrote 8 purchasing SPA files.'

foreach ($p in $patches) {
    $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip
    Say "$([System.IO.Path]::GetFileName($p.Path)) patch: $res"
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
Write-Host 'NOT TOUCHED (referenced, not shown): AppShell.tsx (no PO/Supplier nav item; reachable from the Inventory page header), features/quotes/schemas/quote.ts (formatMoney), usePermission/useTenant hooks.'
