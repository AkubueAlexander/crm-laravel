import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { auditActorsResponseSchema, type AuditActor } from '../schemas/audit';

export function useAuditActors(enabled = true) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['audit', tenant?.id, 'actors'] as const,
        queryFn: async (): Promise<AuditActor[]> => {
            const { data } = await apiClient.get('/audit-log/actors');
            return auditActorsResponseSchema.parse(data).data;
        },
        enabled: enabled && !!tenant?.id,
    });
}
