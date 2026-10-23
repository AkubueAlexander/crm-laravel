import { useQueryClient } from '@tanstack/react-query';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';
import { useTenant } from '@/shared/hooks/useTenant';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import { exportProgressSchema, isExportTerminal, type ContactExport } from '../schemas/contactExport';
import { contactExportKey } from './useContactExport';

// The leading dot stops Echo prefixing its App.Events namespace (the event uses broadcastAs).
const PROGRESS_EVENT = '.contact-export.progress';

// 8b.4: patches the cached export with the pushed status and, when it finishes, re-reads it,
// which is how the signed download link arrives. Shares the per-user channel with the
// notification bell and the import progress (authorized server-side by UserChannel, 1.5).
export function useContactExportLive() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const { data: user } = useCurrentUser();

    const tenantId = tenant?.id;
    const userId = user?.id;
    const channel = tenantId !== undefined && userId !== undefined ? `tenant.${tenantId}.user.${userId}` : '';

    useEchoChannel(channel, PROGRESS_EVENT, (raw) => {
        const parsed = exportProgressSchema.safeParse(raw);
        if (!parsed.success) return;

        const progress = parsed.data;
        const key = contactExportKey(tenantId, progress.id);

        queryClient.setQueryData<ContactExport>(key, (old) =>
            old ? { ...old, status: progress.status, row_count: progress.row_count } : old,
        );

        if (isExportTerminal(progress.status)) {
            void queryClient.invalidateQueries({ queryKey: key });
        }
    });
}
