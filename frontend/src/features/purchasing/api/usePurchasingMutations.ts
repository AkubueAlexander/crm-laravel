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
