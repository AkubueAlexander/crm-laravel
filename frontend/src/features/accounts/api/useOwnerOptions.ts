import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { ownerOptionsResponseSchema, type OwnerOption } from '../schemas/ownerOption';


export function useOwnerOptions() {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['users', tenant?.id, 'options'] as const,
        queryFn: async (): Promise<OwnerOption[]> => {
            const { data } = await apiClient.get('/users/options');
            return ownerOptionsResponseSchema.parse(data).data;
        },
        enabled: !!tenant?.id,
        staleTime: 5 * 60_000,
    });
}
