import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { userRolesResponseSchema, type UserRole } from '../schemas/user';

export function userRolesKey(tenantId: unknown) {
    return ['users', tenantId, 'roles'] as const;
}


export function useUserRoles() {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: userRolesKey(tenant?.id),
        queryFn: async (): Promise<UserRole[]> => {
            const { data } = await apiClient.get('/users/roles');
            return userRolesResponseSchema.parse(data).data;
        },
        enabled: !!tenant?.id,
        staleTime: 10 * 60_000,
    });
}
