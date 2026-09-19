import { QueryClient } from '@tanstack/react-query';
import { isApiError } from './apiClient';

export const queryClient = new QueryClient({
    defaultOptions: {
        queries: {
            staleTime: 30_000,
            retry: (failureCount, error) => {

                if (isApiError(error)) {
                    const status = error.response?.status;
                    if (status && [401, 403, 404, 409, 422].includes(status)) return false;
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
