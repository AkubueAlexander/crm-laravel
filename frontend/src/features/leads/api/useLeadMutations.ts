import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadSchema, type Lead, type CreateLeadInput, type UpdateLeadInput } from '../schemas/lead';

/**
 * Leads (excluding conversion) are last-write-wins, same as Accounts: plain
 * invalidate-on-success. Conversion is its own state-transition mutation and
 * is deliberately NOT here - it's pending on the Deal model (8a.2, blocked).
 */
export function useCreateLead() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: CreateLeadInput): Promise<Lead> => {
            const { data } = await apiClient.post('/leads', input);
            return leadSchema.parse(data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['leads', tenant?.id] });
        },
    });
}

export function useUpdateLead(leadId: Lead['id']) {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: UpdateLeadInput): Promise<Lead> => {
            const { data } = await apiClient.patch(`/leads/${leadId}`, input);
            return leadSchema.parse(data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['leads', tenant?.id] });
        },
    });
}

export function useDeleteLead() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (leadId: Lead['id']): Promise<void> => {
            await apiClient.delete(`/leads/${leadId}`);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['leads', tenant?.id] });
        },
    });
}