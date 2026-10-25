import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import type { CampaignInput } from '../schemas/campaignForm';
import { campaignsListKey } from './useCampaigns';

type UpdateCampaignVariables = { id: number; input: CampaignInput };

// Not optimistic. Invalidates on settled too: a 422 on `status` means the campaign left draft
// meanwhile, so the list on screen is stale. (lock_version arrives with the 9.4 state machine.)
export function useUpdateCampaign() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ id, input }: UpdateCampaignVariables): Promise<void> => {
            await apiClient.put(`/campaigns/${id}`, input);
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: campaignsListKey(tenant?.id) }),
    });
}
