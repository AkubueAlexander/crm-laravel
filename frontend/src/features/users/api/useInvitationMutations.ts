import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { invitationSchema, type Invitation, type InviteFormValues } from '../schemas/invitation';
import { invitationsKey } from './useInvitations';

// Creates an invitation, or re-issues the pending one for the same email (the API answers 200 instead of 201).
export function useCreateInvitation() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (values: InviteFormValues): Promise<{ invitation: Invitation; reissued: boolean }> => {
            const response = await apiClient.post('/users/invitations', values);
            return { invitation: invitationSchema.parse(response.data), reissued: response.status === 200 };
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: invitationsKey(tenant?.id) }),
    });
}

export function useResendInvitation() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (invitationId: number) => {
            await apiClient.post(`/users/invitations/${invitationId}/resend`);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: invitationsKey(tenant?.id) }),
    });
}

export function useRevokeInvitation() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (invitationId: number) => {
            await apiClient.delete(`/users/invitations/${invitationId}`);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: invitationsKey(tenant?.id) }),
    });
}
