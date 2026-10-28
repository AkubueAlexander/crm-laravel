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
