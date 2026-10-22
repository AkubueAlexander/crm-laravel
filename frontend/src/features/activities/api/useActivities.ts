import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { activitiesPageSchema, type ActivitiesPage, type ActivityTargetType } from '../schemas/activity';

type UseActivitiesParams = {
    targetType: ActivityTargetType;
    targetId: number;
    status?: 'open' | 'completed';
    page: number;
};

// 1.2: tenant-namespaced key. The page/status state is part of the key, so flipping
// back to a viewed page is instant.
export function useActivities({ targetType, targetId, status, page }: UseActivitiesParams) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['activities', tenant?.id, targetType, targetId, { page, status }],
        queryFn: async (): Promise<ActivitiesPage> => {
            const { data } = await apiClient.get('/activities', {
                params: {
                    activityable_type: targetType,
                    activityable_id: targetId,
                    status,
                    page,
                },
            });
            return activitiesPageSchema.parse(data);
        },
        enabled: !!tenant && targetId > 0,
        placeholderData: keepPreviousData,
    });
}
