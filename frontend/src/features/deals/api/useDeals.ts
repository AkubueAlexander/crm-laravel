import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { dealSchema, type Deal } from '../schemas/deal';
import { z } from 'zod';

// 1.2: tenant-namespaced query key — prevents a stale cache entry from one
// tenant briefly rendering under another during a session/impersonation switch.
export function useDeals(boardId: number = 1) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['deals', tenant?.id, boardId] as const,
        queryFn: async (): Promise<Deal[]> => {
            const { data } = await apiClient.get('/deals', { params: { board_id: boardId } });
            return z.array(dealSchema).parse(data);
        },
        enabled: !!tenant?.id,
    });
}
