import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadsPageSchema, type LeadSortColumn, type LeadsPage } from '../schemas/lead';

export type LeadFilters = {
    status?: string;
};

export type UseLeadsParams = {
    page: number;
    sort?: LeadSortColumn;
    direction?: 'asc' | 'desc';
    filters?: LeadFilters;
};

// 5.2/8.4 pattern: server-side pagination + sorting via manualPagination/
// manualSorting, tenant-namespaced key so a stale page can't leak across tenants.
export function useLeads({ page, sort, direction, filters }: UseLeadsParams) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['leads', tenant?.id, page, sort, direction, filters],
        queryFn: async (): Promise<LeadsPage> => {
            const { data } = await apiClient.get('/leads', {
                params: { page, sort, direction, status: filters?.status },
            });
            return leadsPageSchema.parse(data);
        },
        enabled: !!tenant?.id,
        placeholderData: (prev) => prev, // keep old page visible while the next loads
    });
}