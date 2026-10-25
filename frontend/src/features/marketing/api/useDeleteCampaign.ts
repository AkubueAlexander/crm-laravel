import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { campaignsListKey } from './useCampaigns';

// Not optimistic on purpose: the user confirms in a dialog first, and the server may still refuse
// (a campaign that left draft meanwhile), so the row only disappears once the API says it is gone.
export function useDeleteCampaign() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (campaignId: number): Promise<void> => {
            await apiClient.delete(`/campaigns/${campaignId}`);
        },
        // Also on error: a refusal usually means the list on screen is stale.
        onSettled: () => queryClient.invalidateQueries({ queryKey: campaignsListKey(tenant?.id) }),
    });
}
