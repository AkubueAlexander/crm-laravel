import { useMutation, useQuery } from '@tanstack/react-query';
import { apiClient, ensureCsrfCookie } from '@/shared/lib/apiClient';
import { invitationPreviewSchema, type InvitationPreview } from '../schemas/invitation';

// Public and unauthenticated: not tenant-namespaced, because the visitor has no tenant yet.
export function useInvitationPreview(token: string | undefined) {
    return useQuery({
        queryKey: ['invitation-preview', token] as const,
        queryFn: async (): Promise<InvitationPreview> => {
            const { data } = await apiClient.get(`/invitations/${encodeURIComponent(token ?? '')}`);
            return invitationPreviewSchema.parse(data);
        },
        enabled: !!token,
        retry: false,
        staleTime: 60_000,
        refetchOnWindowFocus: false,
    });
}

export type AcceptInvitationInput = {
    token: string;
    name: string;
    password: string;
    password_confirmation: string;
};

export function useAcceptInvitation() {
    return useMutation({
        mutationFn: async ({ token, ...body }: AcceptInvitationInput) => {
            // No session exists yet, so fetch the CSRF cookie first (same as the login form).
            await ensureCsrfCookie();
            await apiClient.post(`/invitations/${encodeURIComponent(token)}/accept`, body);
        },
    });
}
