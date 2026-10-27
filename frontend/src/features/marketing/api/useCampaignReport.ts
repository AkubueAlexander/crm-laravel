import { useRef } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';
import { campaignReportSchema, type CampaignReport } from '../schemas/campaignReport';
import { campaignProgressSchema } from '../schemas/campaignProgress';
import { CAMPAIGN_PROGRESS_EVENT } from './useCampaignProgress';

export function campaignReportKey(tenantId: unknown, campaignId: number) {
    return ['campaign-report', tenantId, campaignId] as const;
}

const THROTTLE_MS = 2_000;

// 9.10: the report is server state (TanStack Query). While the campaign is sending it is refreshed by the
// 9.4d progress event (throttled; the event carries counters only, so we refetch rather than patch) with a 10 s
// refetch interval as a fallback. Opens/clicks after the send arrive on window focus / revisit (not pushed live yet).
export function useCampaignReport(campaignId: number) {
    const { tenant } = useTenant();
    const queryClient = useQueryClient();
    const lastInvalidate = useRef(0);
    const key = campaignReportKey(tenant?.id, campaignId);

    const query = useQuery({
        queryKey: key,
        queryFn: async (): Promise<CampaignReport> => {
            const { data } = await apiClient.get(`/campaigns/${campaignId}/report`);
            return campaignReportSchema.parse(data);
        },
        enabled: !!tenant?.id && campaignId > 0,
        refetchInterval: (q) => (q.state.data?.campaign.status === 'sending' ? 10_000 : false),
    });

    useEchoChannel(tenant?.id ? `tenant.${tenant.id}.campaigns` : '', CAMPAIGN_PROGRESS_EVENT, (raw) => {
        const parsed = campaignProgressSchema.safeParse(raw);
        if (!parsed.success || parsed.data.id !== campaignId) return;
        const now = Date.now();
        if (now - lastInvalidate.current < THROTTLE_MS) return;
        lastInvalidate.current = now;
        void queryClient.invalidateQueries({ queryKey: key });
    });

    return query;
}
