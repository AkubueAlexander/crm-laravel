import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { invitationsResponseSchema, type Invitation } from '../schemas/invitation';

export function invitationsKey(tenantId: unknown) {
    return ['users', tenantId, 'invitations'] as const;
}

export function useInvitations() {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: invitationsKey(tenant?.id),
        queryFn: async (): Promise<Invitation[]> => {
            const { data } = await apiClient.get('/users/invitations');
            return invitationsResponseSchema.parse(data).data;
        },
        enabled: !!tenant?.id,
    });
}
