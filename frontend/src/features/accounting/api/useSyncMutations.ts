import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { syncRunSchema, syncStatusSchema, type SyncRun, type SyncStatus } from '../schemas/sync';
import { accountingRootKey } from './useAccounting';

function useInvalidateAccounting() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    return () => void queryClient.invalidateQueries({ queryKey: accountingRootKey(tenant?.id) });
}

// NOT optimistic: the server decides which provider is current. 422 errors.provider on bad input.
export function useSaveSyncProvider() {
    const invalidate = useInvalidateAccounting();
    return useMutation({
        mutationFn: async (provider: string): Promise<SyncStatus> => {
            const { data } = await apiClient.put('/accounting/sync/settings', { provider });
            return syncStatusSchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

// NOT optimistic: the server queues the run. 422 errors.sync when one is already active.
export function useRunSync() {
    const invalidate = useInvalidateAccounting();
    return useMutation({
        mutationFn: async (): Promise<SyncRun> => {
            const { data } = await apiClient.post('/accounting/sync/run');
            return syncRunSchema.parse(data);
        },
        onSuccess: invalidate,
        onError: invalidate,
    });
}
