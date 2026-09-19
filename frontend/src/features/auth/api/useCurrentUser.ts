import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { currentUserSchema, type CurrentUser } from '../schemas/auth';


export function useCurrentUser() {
    return useQuery({
        queryKey: ['auth', 'me'] as const,
        queryFn: async (): Promise<CurrentUser> => {
            const { data } = await apiClient.get('/me');

            return currentUserSchema.parse(data);
        },

        retry: false,
        staleTime: 5 * 60_000,
    });
}
