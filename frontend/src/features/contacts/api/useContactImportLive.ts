import { useQueryClient } from '@tanstack/react-query';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';
import { useTenant } from '@/shared/hooks/useTenant';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import { importProgressSchema, isImportTerminal, type ContactImport } from '../schemas/contactImport';
import { contactImportKey } from './useContactImport';

// The leading dot stops Echo prefixing its App.Events namespace (the event uses broadcastAs).
const PROGRESS_EVENT = '.contact-import.progress';

// 8b.3: patches the cached import with pushed counters, and when it finishes refreshes the
// contacts list so new rows appear without polling. Shares the per-user channel with the
// notification bell (authorized server-side by UserChannel, 1.5).
export function useContactImportLive() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const { data: user } = useCurrentUser();

    const tenantId = tenant?.id;
    const userId = user?.id;
    const channel = tenantId !== undefined && userId !== undefined ? `tenant.${tenantId}.user.${userId}` : '';

    useEchoChannel(channel, PROGRESS_EVENT, (raw) => {
        const parsed = importProgressSchema.safeParse(raw);
        if (!parsed.success) return;

        const progress = parsed.data;

        queryClient.setQueryData<ContactImport>(contactImportKey(tenantId, progress.id), (old) =>
            old ? { ...old, ...progress } : old,
        );

        if (isImportTerminal(progress.status)) {
            // Also refetches the import itself, which is how the report arrives.
            void queryClient.invalidateQueries({ queryKey: ['contacts', tenantId] });
        }
    });
}
