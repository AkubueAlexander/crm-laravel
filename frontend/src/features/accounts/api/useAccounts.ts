import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { accountsPageSchema, type AccountSortColumn, type AccountsPage } from '../schemas/account';

export type AccountsListParams = {
    page?: number;
    perPage?: number;
    sort?: AccountSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
};

/**
 * 8a.1/8.4: server-side pagination/sorting/search. The query key carries every
 * param that changes the result set, namespaced by tenant id per 1.2.
 */
export function useAccounts(params: AccountsListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'name', direction = 'asc', q = '' } = params;

    return useQuery({
        queryKey: ['accounts', tenant?.id, { page, perPage, sort, direction, q }] as const,
        queryFn: async (): Promise<AccountsPage> => {
            const { data } = await apiClient.get('/accounts', {
                params: {
                    page,
                    per_page: perPage,
                    sort,
                    direction,
                    q: q || undefined,
                },
            });
            return accountsPageSchema.parse(data);
        },
        enabled: !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}