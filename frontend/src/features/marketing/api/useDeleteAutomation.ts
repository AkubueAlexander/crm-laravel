import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { automationsListKey } from './useAutomations';

// Not optimistic on purpose: the user confirms in a dialog first, and the server refuses to delete an active
// automation, so the row only disappears once the API says it is gone.
export function useDeleteAutomation() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (automationId: number): Promise<void> => {
            await apiClient.delete(`/automations/${automationId}`);
        },
        // Also on error: a 404 or a refusal usually means the list on screen is stale.
        onSettled: () => queryClient.invalidateQueries({ queryKey: automationsListKey(tenant?.id) }),
    });
}