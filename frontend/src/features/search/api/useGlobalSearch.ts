import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {  SEARCH_MIN_LENGTH, searchResponseSchema, type SearchResponse  } from '../schemas/search';


export function useGlobalSearch(term: string) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['search', tenant?.id, term],
        queryFn: async ({ signal }):Promise<SearchResponse> => {
            const {data} = await apiClient.get('/search', { params: { q: term }, signal });
            return searchResponseSchema.parse(data);
        },
        enabled: !!tenant && term.length >= SEARCH_MIN_LENGTH,
        staleTime: 30_000,
        placeholderData: keepPreviousData,
    });
}
