// queryClient.ts: use axios.isAxiosError instead of isApiError
import { QueryClient } from '@tanstack/react-query';
import axios from 'axios';

const NO_RETRY_STATUSES = [401, 403, 404, 409, 422];

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            retry: (failureCount, error) => {
                if (axios.isAxiosError(error)) {
                    const status = error.response?.status;
                    if (status && NO_RETRY_STATUSES.includes(status)) return false;
                }
                return failureCount < 2;
            },
            refetchOnWindowFocus: true,
        },
        mutations: {
            retry: false,
        },
    },
});
