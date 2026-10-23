import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import { NOTIFICATIONS_PER_PAGE, notificationsResponseSchema } from '../schemas/notification';

// The one place that builds the notifications query key. Namespaced by tenant (1.2)
// AND user, so neither a tenant switch nor an impersonation can show a stale inbox.
export function useNotificationsScope() {
    const { tenant } = useTenant();
    const { data: user } = useCurrentUser();

    return {
        key: ['notifications', tenant?.id, user?.id] as const,
        tenantId: tenant?.id,
        userId: user?.id,
        ready: tenant !== undefined && user !== undefined,
    };
}

// 8b.2: latest page only (the dropdown). Kept live by useNotificationsLive, not polling.
export function useNotifications() {
    const { key, ready } = useNotificationsScope();

    return useQuery({
        queryKey: key,
        queryFn: async ({ signal }) => {
            const res = await apiClient.get('/notifications', {
                params: { per_page: NOTIFICATIONS_PER_PAGE },
                signal,
            });
            return notificationsResponseSchema.parse(res.data);
        },
        enabled: ready,
        staleTime: 30_000,
    });
}
