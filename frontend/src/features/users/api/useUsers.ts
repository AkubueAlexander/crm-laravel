import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { usersPageSchema, type UserSortColumn, type UserStatus, type UsersPage } from '../schemas/user';

export type UsersListParams = {
    page?: number;
    perPage?: number;
    sort?: UserSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    role?: string;
    status?: UserStatus;
};

// Prefix shared by every cached list page of a tenant (the options query uses ['users', id, 'options']).
export function usersListKey(tenantId: unknown) {
    return ['users', tenantId, 'list'] as const;
}

export function useUsers(params: UsersListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'name', direction = 'asc', q = '', role = '', status } = params;

    return useQuery({
        queryKey: [...usersListKey(tenant?.id), { page, perPage, sort, direction, q, role, status }] as const,
        queryFn: async (): Promise<UsersPage> => {
            const { data } = await apiClient.get('/users', {
                params: {
                    page,
                    per_page: perPage,
                    sort,
                    direction,
                    q: q || undefined,
                    role: role || undefined,
                    status: status || undefined,
                },
            });
            return usersPageSchema.parse(data);
        },
        enabled: !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}
