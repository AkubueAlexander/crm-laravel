import { useQueryClient } from '@tanstack/react-query';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';
import { useNotificationsScope } from './useNotifications';

// Laravel broadcasts database notifications as BroadcastNotificationCreated. The leading
// dot tells Echo not to prefix its App.Events namespace onto the event name.
const NOTIFICATION_EVENT = '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated';

// 6.4: the pushed event is only an "invalidate" signal. The inbox itself is always
// re-read through the API, so the client never trusts or stores the pushed payload.
export function useNotificationsLive() {
    const queryClient = useQueryClient();
    const { key, tenantId, userId } = useNotificationsScope();

    // Same name the server authorizes in UserChannel (1.5). Empty until both ids are known.
    const channel = tenantId !== undefined && userId !== undefined ? `tenant.${tenantId}.user.${userId}` : '';

    useEchoChannel(channel, NOTIFICATION_EVENT, () => {
        void queryClient.invalidateQueries({ queryKey: key });
    });
}
