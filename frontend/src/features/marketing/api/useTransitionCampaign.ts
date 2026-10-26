import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTenant } from '@/shared/hooks/useTenant';
import { CAMPAIGN_STATUSES, type Campaign, type CampaignStatus, type CampaignsPage } from '../schemas/campaign';
import { campaignsListKey } from './useCampaigns';

// Mirrors CampaignLifecycleResource (9.4a): the transition endpoint's response and the `current` of a 409.
const lifecycleSchema = z.object({
    id: z.number(),
    status: z.enum(CAMPAIGN_STATUSES),
    lock_version: z.number(),
    segment_id: z.number().nullable(),
    email_template_id: z.number().nullable(),
    scheduled_at: z.string().nullable(),
    started_at: z.string().nullable(),
    completed_at: z.string().nullable(),
    sent_at: z.string().nullable(),
    recipients_count: z.number(),
    sent_count: z.number(),
    failed_count: z.number(),
});

export type CampaignLifecycle = z.infer<typeof lifecycleSchema>;

export type TransitionVars = {
    campaign: Campaign;
    toStatus: CampaignStatus;
    // ISO instant; only for toStatus = 'scheduled'.
    scheduledAt?: string;
};

type Snapshot = Array<[QueryKey, CampaignsPage | undefined]>;

function patchRow(page: CampaignsPage | undefined, id: number, fn: (c: Campaign) => Campaign): CampaignsPage | undefined {
    if (!page) return page;
    return { ...page, data: page.data.map((c) => (c.id === id ? fn(c) : c)) };
}

export const STALE_CAMPAIGN_MESSAGE = 'This campaign was updated by someone else - refresh to see the latest status.';

export function isStaleCampaignError(err: unknown): boolean {
    return isApiError(err) && err.response?.status === 409;
}

// 409 = lock_version mismatch (3.0 pattern). 422 (audience_missing, schedule_invalid, invalid_transition) carries
// the server's own sentence, which apiErrorMessage prefers.
export function transitionErrorMessage(err: unknown): string {
    if (isStaleCampaignError(err)) return STALE_CAMPAIGN_MESSAGE;
    return apiErrorMessage(err, 'Could not update the campaign. Please try again.');
}

// 3.0 pattern: optimistic patch of every cached list page, roll back on any error, reconcile with the response,
// then invalidate. Invalidation uses campaignsListKey so the 9.4d live progress cache stays the single source.
export function useTransitionCampaign() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const listKey = campaignsListKey(tenant?.id);

    return useMutation<CampaignLifecycle, unknown, TransitionVars, { previous: Snapshot }>({
        mutationFn: async ({ campaign, toStatus, scheduledAt }) => {
            const { data } = await apiClient.post(`/campaigns/${campaign.id}/transition`, {
                to_status: toStatus,
                lock_version: campaign.lock_version,
                ...(scheduledAt ? { scheduled_at: scheduledAt } : {}),
            });
            return lifecycleSchema.parse(data);
        },
        onMutate: async (vars) => {
            await queryClient.cancelQueries({ queryKey: listKey });
            const previous = queryClient.getQueriesData<CampaignsPage>({ queryKey: listKey });

            queryClient.setQueriesData<CampaignsPage>({ queryKey: listKey }, (old) =>
                patchRow(old, vars.campaign.id, (c) => ({
                    ...c,
                    status: vars.toStatus,
                    editable: vars.toStatus === 'draft',
                    scheduled_at:
                        vars.toStatus === 'scheduled' ? (vars.scheduledAt ?? c.scheduled_at) : vars.toStatus === 'draft' ? null : c.scheduled_at,
                })),
            );

            return { previous };
        },
        onError: (_err, _vars, context) => {
            context?.previous.forEach(([key, data]) => queryClient.setQueryData(key, data));
        },
        onSuccess: (res) => {
            queryClient.setQueriesData<CampaignsPage>({ queryKey: listKey }, (old) =>
                patchRow(old, res.id, (c) => ({ ...c, ...res, editable: res.status === 'draft' })),
            );
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: listKey }),
    });
}