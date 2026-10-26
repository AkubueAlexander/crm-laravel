import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { automationSchema } from '../schemas/automation';
import type { AutomationPayload } from '../schemas/automationForm';
import { automationDetailKey } from './useAutomation';
import { automationsListKey } from './useAutomations';

// Not optimistic: a definition is validated server-side (AutomationDsl) and a save can legitimately fail with 422,
// so the UI waits for the real response. A save bumps lock_version, so the detail and list caches are refetched.

export function useCreateAutomation() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (payload: AutomationPayload) => {
            const { data } = await apiClient.post('/automations', payload);
            return automationSchema.parse(data);
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: automationsListKey(tenant?.id) }),
    });
}

export function useUpdateAutomation(id: number) {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (payload: AutomationPayload) => {
            const { data } = await apiClient.put(`/automations/${id}`, payload);
            return automationSchema.parse(data);
        },
        onSettled: async () => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: automationsListKey(tenant?.id) }),
                queryClient.invalidateQueries({ queryKey: automationDetailKey(tenant?.id, id) }),
            ]);
        },
    });
}