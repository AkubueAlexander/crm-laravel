import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { accountOptionsResponseSchema, type AccountOption } from '../schemas/accountOption';


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
