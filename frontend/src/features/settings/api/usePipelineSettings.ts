import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    pipelineSettingsSchema,
    type PipelineSettings,
    type StageKey,
} from '../schemas/settings';

// Tenant-namespaced (1.2). Server state, so it lives in TanStack Query only (8.1).
export function pipelineSettingsKey(tenantId: string | number | undefined) {
    return ['settings', tenantId, 'pipeline'] as const;
}

export function usePipelineSettings() {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: pipelineSettingsKey(tenant?.id),
        queryFn: async ({ signal }): Promise<PipelineSettings> => {
            const { data } = await apiClient.get('/settings/pipeline', { signal });
            return pipelineSettingsSchema.parse(data);
        },
        enabled: !!tenant,
        staleTime: 30_000,
    });
}

export function useUpdatePipelineSettings() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (stages: Record<StageKey, number>): Promise<PipelineSettings> => {
            const { data } = await apiClient.put('/settings/pipeline', { stages });
            return pipelineSettingsSchema.parse(data);
        },
        onSuccess: (saved) => {
            queryClient.setQueryData(pipelineSettingsKey(tenant?.id), saved);
            // 4.3: the forecast dashboard must reflect new probabilities immediately.
            void queryClient.invalidateQueries({ queryKey: ['forecast', tenant?.id] });
        },
    });
}
