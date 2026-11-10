import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import type { UserRole, UsersPage } from '../schemas/user';
import { usersListKey } from './useUsers';
import { userRolesKey } from './useUserRoles';


export function useChangeUserRole() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const tenantId = tenant?.id;

    return useMutation({
        mutationFn: async ({ userId, role }: { userId: number; role: string }) => {
            await apiClient.patch(`/users/${userId}/role`, { role });
        },
        onMutate: async ({ userId, role }) => {
            await queryClient.cancelQueries({ queryKey: usersListKey(tenantId) });

            const snapshots = queryClient.getQueriesData<UsersPage>({ queryKey: usersListKey(tenantId) });
            const roles = queryClient.getQueryData<UserRole[]>(userRolesKey(tenantId));
            const label = roles?.find((r) => r.name === role)?.label ?? role;

            queryClient.setQueriesData<UsersPage>({ queryKey: usersListKey(tenantId) }, (old) =>
                old
                    ? {
                          ...old,
                          data: old.data.map((u) => (u.id === userId ? { ...u, role, role_label: label } : u)),
                      }
                    : old,
            );

            return { snapshots };
        },
        onError: (_error, _vars, context) => {
            context?.snapshots.forEach(([key, data]) => queryClient.setQueryData(key, data));
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: usersListKey(tenantId) }),
    });
}


export function useSetUserActive() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const tenantId = tenant?.id;

    return useMutation({
        mutationFn: async ({ userId, active }: { userId: number; active: boolean }) => {
            await apiClient.post(`/users/${userId}/${active ? 'reactivate' : 'deactivate'}`);
        },
        // Whole ['users', tenantId] prefix: the owner-options list (accounts) changes too.
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['users', tenantId] }),
    });
}
