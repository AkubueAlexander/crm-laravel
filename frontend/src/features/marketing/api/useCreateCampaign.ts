import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import type { CampaignInput } from '../schemas/campaignForm';
import { campaignsListKey } from './useCampaigns';

// Not optimistic: the server owns the unique-name rule and the draft status, so the new row
// appears only once the API accepted it. Tenant comes from the session, never from the body.
export function useCreateCampaign() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: CampaignInput): Promise<void> => {
            await apiClient.post('/campaigns', input);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: campaignsListKey(tenant?.id) }),
    });
}
