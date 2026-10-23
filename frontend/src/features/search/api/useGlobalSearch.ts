import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { SEARCH_MIN_LENGTH, searchResponseSchema } from '../schemas/search';

// 8b.1: pass an already-debounced term (useDebouncedValue, 300ms). The key is
// tenant-namespaced (1.2) so a tenant switch can never show another tenant's hits.
export function useGlobalSearch(term: string) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['search', tenant?.id, term],
        queryFn: async ({ signal }) => {
            const res = await apiClient.get('/search', { params: { q: term }, signal });
            return searchResponseSchema.parse(res.data);
        },
        enabled: !!tenant && term.length >= SEARCH_MIN_LENGTH,
        staleTime: 30_000,
        placeholderData: keepPreviousData,
    });
}
