import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { contactsPageSchema, type ContactSortColumn, type ContactsPage } from '../schemas/contact';

export type ContactsListParams = {
    page?: number;
    perPage?: number;
    sort?: ContactSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
};

/**
 * 5.2/8.7: server-side pagination/sorting/search — the query key includes every
 * param that changes the result set, tenant-namespaced per 1.2. URL persistence
 * (8.7) is the route's job: pass values in from useSearch() when that route is wired.
 */
export function useContacts(params: ContactsListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'last_name', direction = 'asc', q = '' } = params;

    return useQuery({
        queryKey: ['contacts', tenant?.id, { page, perPage, sort, direction, q }] as const,
        queryFn: async (): Promise<ContactsPage> => {
            const { data } = await apiClient.get('/contacts', {
                params: {
                    page,
                    per_page: perPage,
                    sort,
                    direction,
                    q: q || undefined,
                },
            });
            return contactsPageSchema.parse(data);
        },
        enabled: !!tenant?.id,
        placeholderData: keepPreviousData, // flipping pages is instant, no flash (5.2)
    });
}