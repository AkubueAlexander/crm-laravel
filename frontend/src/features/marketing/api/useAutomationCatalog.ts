import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { automationCatalogSchema, type AutomationCatalogResponse } from '../schemas/automationForm';

export const automationCatalogKey = (tenantId: unknown) => ['automations', tenantId, 'catalog'] as const;

// The catalog is code on the server (AutomationDsl), so it only changes on a deploy: cache it for the session.
export function useAutomationCatalog() {
    const { tenant } = useTenant();

    return useQuery<AutomationCatalogResponse>({
        queryKey: automationCatalogKey(tenant?.id),
        queryFn: async () => {
            const { data } = await apiClient.get('/automations/catalog');
            return automationCatalogSchema.parse(data);
        },
        enabled: !!tenant?.id,
        staleTime: Infinity,
    });
}