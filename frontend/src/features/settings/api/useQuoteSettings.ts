import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { quoteSettingsSchema, type QuoteSettings } from '../schemas/quoteSettings';

// Tenant-namespaced (1.2). Server state, so it lives in TanStack Query only (8.1).
export function quoteSettingsKey(tenantId: string | number | undefined) {
    return ['settings', tenantId, 'quotes'] as const;
}

export function useQuoteSettings() {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: quoteSettingsKey(tenant?.id),
        queryFn: async ({ signal }): Promise<QuoteSettings> => {
            const { data } = await apiClient.get('/settings/quotes', { signal });
            return quoteSettingsSchema.parse(data);
        },
        enabled: !!tenant,
        staleTime: 30_000,
    });
}

export function useUpdateQuoteSettings() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (payload: { approval_threshold: string | null }): Promise<QuoteSettings> => {
            const { data } = await apiClient.put('/settings/quotes', payload);
            return quoteSettingsSchema.parse(data);
        },
        onSuccess: (saved) => {
            queryClient.setQueryData(quoteSettingsKey(tenant?.id), saved);
        },
    });
}
