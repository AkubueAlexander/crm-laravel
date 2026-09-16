import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useNavigate } from '@tanstack/react-router';
import { apiClient, ensureCsrfCookie } from '@/shared/lib/apiClient';
import type { LoginInput } from '../schemas/auth';

export function useLogin() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (input: LoginInput) => {
            await ensureCsrfCookie();
            await apiClient.post('/login', input);
            await queryClient.refetchQueries({ queryKey: ['auth', 'me'] });
        },
    });
}

export function useLogout() {
    const queryClient = useQueryClient();
    const navigate = useNavigate();

    return useMutation({
        mutationFn: async () => {
            await apiClient.post('/logout');
        },
        onSuccess: () => {

            queryClient.clear();


            navigate({ to: '/login' });
        },
    });
}
