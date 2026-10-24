import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { auditPageSchema, type AuditPage, type AuditSource } from '../schemas/audit';

export type AuditLogParams = {
    page?: number;
    perPage?: number;
    source?: AuditSource;
    userId?: number;
    from?: string;
    to?: string;
    enabled?: boolean;
};

// Prefix shared by every audit query of a tenant (the actors query uses ['audit', id, 'actors']).
export function auditListKey(tenantId: unknown) {
    return ['audit', tenantId, 'list'] as const;
}

export function useAuditLog(params: AuditLogParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, source, userId, from, to, enabled = true } = params;

    return useQuery({
        queryKey: [...auditListKey(tenant?.id), { page, perPage, source, userId, from, to }] as const,
        queryFn: async (): Promise<AuditPage> => {
            const { data } = await apiClient.get('/audit-log', {
                params: {
                    page,
                    per_page: perPage,
                    source: source || undefined,
                    user_id: userId || undefined,
                    from: from || undefined,
                    to: to || undefined,
                },
            });
            return auditPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}
