import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { campaignsPageSchema, type CampaignSortColumn, type CampaignStatus, type CampaignsPage } from '../schemas/campaign';

export type CampaignsListParams = {
    page?: number;
    perPage?: number;
    sort?: CampaignSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    status?: CampaignStatus;
    enabled?: boolean;
};

// Prefix shared by every cached list page of a tenant: mutations invalidate it as a whole.
export function campaignsListKey(tenantId: unknown) {
    return ['campaigns', tenantId, 'list'] as const;
}

export function useCampaigns(params: CampaignsListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'created_at', direction = 'desc', q = '', status, enabled = true } = params;

    return useQuery({
        queryKey: [...campaignsListKey(tenant?.id), { page, perPage, sort, direction, q, status }] as const,
        queryFn: async (): Promise<CampaignsPage> => {
            const { data } = await apiClient.get('/campaigns', {
                params: {
                    page,
                    per_page: perPage,
                    sort,
                    direction,
                    q: q || undefined,
                    status: status || undefined,
                },
            });
            return campaignsPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}
