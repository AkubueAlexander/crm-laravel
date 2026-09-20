import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { accountSchema, type Account, type CreateAccountInput, type UpdateAccountInput } from '../schemas/account';

/**
 * Accounts are last-write-wins, not a state machine: plain invalidate-on-success,
 * no optimistic patch or 409 rollback (that pattern is reserved for Deals-style
 * state machines).
 */
export function useCreateAccount() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: CreateAccountInput): Promise<Account> => {
            const { data } = await apiClient.post('/accounts', input);
            return accountSchema.parse(data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['accounts', tenant?.id] });
        },
    });
}

export function useUpdateAccount(accountId: Account['id']) {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: UpdateAccountInput): Promise<Account> => {
            const { data } = await apiClient.patch(`/accounts/${accountId}`, input);
            return accountSchema.parse(data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['accounts', tenant?.id] });
        },
    });
}

export function useDeleteAccount() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (accountId: Account['id']): Promise<void> => {
            await apiClient.delete(`/accounts/${accountId}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['accounts', tenant?.id] });
        },
    });
}