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
