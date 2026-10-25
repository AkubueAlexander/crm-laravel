import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { segmentsPageSchema, type SegmentSortColumn, type SegmentsPage } from '../schemas/segment';

export type SegmentsListParams = {
    page?: number;
    perPage?: number;
    sort?: SegmentSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    enabled?: boolean;
};

// Prefix shared by every cached list page of a tenant: mutations invalidate it as a whole.
export function segmentsListKey(tenantId: unknown) {
    return ['segments', tenantId, 'list'] as const;
}

export function useSegments(params: SegmentsListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'created_at', direction = 'desc', q = '', enabled = true } = params;

    return useQuery({
        queryKey: [...segmentsListKey(tenant?.id), { page, perPage, sort, direction, q }] as const,
        queryFn: async (): Promise<SegmentsPage> => {
            const { data } = await apiClient.get('/segments', {
                params: {
                    page,
                    per_page: perPage,
                    sort,
                    direction,
                    q: q || undefined,
                },
            });
            return segmentsPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}
