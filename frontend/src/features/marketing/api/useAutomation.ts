import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { automationSchema, type Automation } from '../schemas/automation';

export const automationDetailKey = (tenantId: unknown, id: number) => ['automations', tenantId, 'detail', id] as const;

/** Pass null for the "new automation" route: the query stays disabled. */
export function useAutomation(id: number | null) {
    const { tenant } = useTenant();

    return useQuery<Automation>({
        queryKey: automationDetailKey(tenant?.id, id ?? 0),
        queryFn: async () => {
            const { data } = await apiClient.get(`/automations/${id}`);
            return automationSchema.parse(data);
        },
        enabled: id !== null && !!tenant?.id,
    });
}