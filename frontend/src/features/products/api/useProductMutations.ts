import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { productSchema, type Product } from '../schemas/product';
import type { ProductInput } from '../schemas/productForm';
import { productsRootKey } from './useProducts';

type SaveVars = { id: number | null; input: ProductInput };

// Not optimistic: the server owns the SKU-uniqueness rule, so the row appears only once the API accepted it.
// Tenant comes from the session, never from the body (1.3).
export function useSaveProduct() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ id, input }: SaveVars): Promise<Product> => {
            const { data } = id === null ? await apiClient.post('/products', input) : await apiClient.put(`/products/${id}`, input);
            return productSchema.parse(data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: productsRootKey(tenant?.id) }),
    });
}

// Not optimistic: the user confirms first, and the server may still refuse (e.g. once quotes reference a product).
export function useDeleteProduct() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (productId: number): Promise<void> => {
            await apiClient.delete(`/products/${productId}`);
        },
        // Also on error: a refusal usually means the list on screen is stale.
        onSettled: () => queryClient.invalidateQueries({ queryKey: productsRootKey(tenant?.id) }),
    });
}
