import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { accountOptionsResponseSchema, type AccountOption } from '../schemas/accountOption';

/**
 * Tenant-namespaced per 1.2. Lives under the 'accounts' key on purpose: account
 * mutations invalidate ['accounts', tenantId] by prefix, so renames/deletes/creates
 * refresh this picker automatically.
 *
 * Pass enabled=false when the caller lacks accounts.view (the endpoint would 403).
 */
export function useAccountOptions(enabled = true) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['accounts', tenant?.id, 'options'] as const,
        queryFn: async (): Promise<AccountOption[]> => {
            const { data } = await apiClient.get('/accounts/options');
            return accountOptionsResponseSchema.parse(data).data;
        },
        enabled: enabled && !!tenant?.id,
        staleTime: 60_000,
    });
}