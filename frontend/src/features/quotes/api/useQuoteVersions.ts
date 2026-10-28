import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { quoteSchema, type Quote } from '../schemas/quote';
import { quotesRootKey } from './useQuotes';

// Not paginated: the endpoint returns { data: [...] } (newest version first, no lines).
const versionsSchema = z.object({ data: z.array(quoteSchema) });

// Tenant-namespaced key under the quotes root, so any quote mutation refreshes it as well.
export function useQuoteVersions(quoteId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: [...quotesRootKey(tenant?.id), 'versions', quoteId] as const,
        queryFn: async (): Promise<Quote[]> => {
            const { data } = await apiClient.get(`/quotes/${quoteId}/versions`);
            return versionsSchema.parse(data).data;
        },
        enabled: !!tenant?.id && quoteId !== null,
        staleTime: 0,
    });
}

// Not optimistic: the server allocates the new version and decides whether the source can be revised.
export function useReviseQuote() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (quoteId: number): Promise<Quote> => {
            const { data } = await apiClient.post(`/quotes/${quoteId}/revise`);
            return quoteSchema.parse(data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: quotesRootKey(tenant?.id) }),
    });
}
