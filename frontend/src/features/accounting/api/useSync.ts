import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { syncRunsPageSchema, syncStatusSchema, type SyncRunsPage, type SyncStatus } from '../schemas/sync';
import { accountingRootKey } from './useAccounting';

const POLL_MS = 3000;

// 12.2c: status polls ONLY while a run is active; no polling otherwise.
export function useSyncStatus(enabled: boolean) {
    const { tenant } = useTenant();
    return useQuery<SyncStatus>({
        queryKey: [...accountingRootKey(tenant?.id), 'sync', 'status'],
        enabled: enabled && !!tenant,
        queryFn: async () => {
            const { data } = await apiClient.get('/accounting/sync');
            return syncStatusSchema.parse(data);
        },
        refetchInterval: (query) => (query.state.data?.active_run ? POLL_MS : false),
    });
}

export function useSyncRuns({ page, enabled, poll }: { page: number; enabled: boolean; poll: boolean }) {
    const { tenant } = useTenant();
    return useQuery<SyncRunsPage>({
        queryKey: [...accountingRootKey(tenant?.id), 'sync', 'runs', { page }],
        enabled: enabled && !!tenant,
        placeholderData: keepPreviousData,
        queryFn: async () => {
            const { data } = await apiClient.get('/accounting/sync/runs', { params: { page, per_page: 15 } });
            return syncRunsPageSchema.parse(data);
        },
        refetchInterval: poll ? POLL_MS : false,
    });
}
