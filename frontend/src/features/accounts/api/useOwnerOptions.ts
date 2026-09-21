import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { ownerOptionsResponseSchema, type OwnerOption } from '../schemas/ownerOption';

/**
 * Tenant-namespaced per 1.2. Keyed under 'users' (not 'accounts') so account
 * mutations don't needlessly refetch it and later owner selects (deals, leads)
 * can share the same cache entry. Move to shared/ when a second feature uses it.
 */
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