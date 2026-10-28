import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { quoteSchema, quotesPageSchema, type Quote, type QuoteSortColumn, type QuoteStatus, type QuotesPage } from '../schemas/quote';

export type QuotesListParams = {
    page?: number;
    perPage?: number;
    sort?: QuoteSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    status?: QuoteStatus;
    enabled?: boolean;
};

// Tenant-namespaced keys (1.2). The root prefix covers lists and details: mutations invalidate it as a whole.
export function quotesRootKey(tenantId: unknown) {
    return ['quotes', tenantId] as const;
}

export function quotesListKey(tenantId: unknown) {
    return [...quotesRootKey(tenantId), 'list'] as const;
}

export function useQuotes(params: QuotesListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'quote_number', direction = 'desc', q = '', status, enabled = true } = params;

    return useQuery({
        queryKey: [...quotesListKey(tenant?.id), { page, perPage, sort, direction, q, status }] as const,
        queryFn: async (): Promise<QuotesPage> => {
            const { data } = await apiClient.get('/quotes', {
                params: { page, per_page: perPage, sort, direction, q: q || undefined, status: status || undefined },
            });
            return quotesPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}

// The list carries lines_count only; the builder needs the lines, so it loads the detail fresh.
export function useQuote(quoteId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: [...quotesRootKey(tenant?.id), 'detail', quoteId] as const,
        queryFn: async (): Promise<Quote> => {
            const { data } = await apiClient.get(`/quotes/${quoteId}`);
            return quoteSchema.parse(data);
        },
        enabled: !!tenant?.id && quoteId !== null,
        staleTime: 0,
    });
}
