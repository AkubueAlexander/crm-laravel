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
