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
        placeholderData: keepPreviousData,
    });
}
