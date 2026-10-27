import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    productSchema,
    productsPageSchema,
    type Product,
    type ProductSortColumn,
    type ProductStatusFilter,
    type ProductsPage,
    type TaxCategory,
} from '../schemas/product';

export type ProductsListParams = {
    page?: number;
    perPage?: number;
    sort?: ProductSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    status?: ProductStatusFilter;
    taxCategory?: TaxCategory;
    enabled?: boolean;
};

// Tenant-namespaced keys (1.2). The root prefix covers lists and details: mutations invalidate it as a whole.
export function productsRootKey(tenantId: unknown) {
    return ['products', tenantId] as const;
}

export function productsListKey(tenantId: unknown) {
    return [...productsRootKey(tenantId), 'list'] as const;
}

export function useProducts(params: ProductsListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'name', direction = 'asc', q = '', status, taxCategory, enabled = true } = params;

    return useQuery({
        queryKey: [...productsListKey(tenant?.id), { page, perPage, sort, direction, q, status, taxCategory }] as const,
        queryFn: async (): Promise<ProductsPage> => {
            const { data } = await apiClient.get('/products', {
                params: {
                    page,
                    per_page: perPage,
                    sort,
                    direction,
                    q: q || undefined,
                    status: status || undefined,
                    tax_category: taxCategory || undefined,
                },
            });
            return productsPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}

// The list carries variants_count only; the edit dialog needs the variants, so it loads the detail fresh.
export function useProduct(productId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: [...productsRootKey(tenant?.id), 'detail', productId] as const,
        queryFn: async (): Promise<Product> => {
            const { data } = await apiClient.get(`/products/${productId}`);
            return productSchema.parse(data);
        },
        enabled: !!tenant?.id && productId !== null,
        staleTime: 0,
    });
}
