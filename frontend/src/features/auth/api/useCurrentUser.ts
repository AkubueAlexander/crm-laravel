import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { currentUserSchema, type CurrentUser } from '../schemas/auth';


export function useCurrentUser() {
    return useQuery({
        queryKey: ['auth', 'me'] as const,
        queryFn: async (): Promise<CurrentUser> => {
            const { data } = await apiClient.get('/me');
            // 7.0: Zod .parse() throws loudly in dev on a Resource/schema drift
            // instead of silently rendering `undefined` in production.
            return currentUserSchema.parse(data);
        },
        // A 401 here is an expected, common state (logged out) — don't retry it,
        // and don't treat it as "stale, keep showing old data."
        retry: false,
        staleTime: 5 * 60_000,
    });
}
