import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import type { NotificationsResponse } from '../schemas/notification';
import { useNotificationsScope } from './useNotifications';

// Both mutations patch the cached inbox optimistically, roll back on error, and
// always reconcile with the server afterwards (the same shape as useMoveDealStage, 3.2).

export function useMarkNotificationRead() {
    const queryClient = useQueryClient();
    const { key } = useNotificationsScope();

    return useMutation({
        mutationFn: async (id: string) => {
            await apiClient.post(`/notifications/${id}/read`);
        },
        onMutate: async (id) => {
            await queryClient.cancelQueries({ queryKey: key });
            const previous = queryClient.getQueryData<NotificationsResponse>(key);

            const target = previous?.data.find((n) => n.id === id);
            if (previous && target && !target.read_at) {
                queryClient.setQueryData<NotificationsResponse>(key, {
                    data: previous.data.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n)),
                    meta: { ...previous.meta, unread_count: Math.max(previous.meta.unread_count - 1, 0) },
                });
            }

            return { previous };
        },
        onError: (_error, _id, context) => {
            if (context?.previous) queryClient.setQueryData(key, context.previous);
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: key }),
    });
}

export function useMarkAllNotificationsRead() {
    const queryClient = useQueryClient();
    const { key } = useNotificationsScope();

    return useMutation({
        mutationFn: async () => {
            await apiClient.post('/notifications/read-all');
        },
        onMutate: async () => {
            await queryClient.cancelQueries({ queryKey: key });
            const previous = queryClient.getQueryData<NotificationsResponse>(key);

            if (previous) {
                const now = new Date().toISOString();
                queryClient.setQueryData<NotificationsResponse>(key, {
                    data: previous.data.map((n) => (n.read_at ? n : { ...n, read_at: now })),
                    meta: { ...previous.meta, unread_count: 0 },
                });
            }

            return { previous };
        },
        onError: (_error, _vars, context) => {
            if (context?.previous) queryClient.setQueryData(key, context.previous);
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: key }),
    });
}
