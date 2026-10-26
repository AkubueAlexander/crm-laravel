import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { automationsPageSchema, type AutomationSortColumn, type AutomationsPage } from '../schemas/automation';

export type AutomationsListParams = {
    page?: number;
    sort?: AutomationSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    enabled?: boolean;
};

// Prefix shared by every cached list page of a tenant: mutations invalidate it as a whole (1.2).
export function automationsListKey(tenantId: unknown) {
    return ['automations', tenantId, 'list'] as const;
}

export function useAutomations(params: AutomationsListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, sort = 'created_at', direction = 'desc', q = '', enabled = true } = params;

    return useQuery({
        queryKey: [...automationsListKey(tenant?.id), { page, sort, direction, q }] as const,
        queryFn: async (): Promise<AutomationsPage> => {
            const { data } = await apiClient.get('/automations', {
                params: { page, sort, direction, q: q || undefined },
            });
            return automationsPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}