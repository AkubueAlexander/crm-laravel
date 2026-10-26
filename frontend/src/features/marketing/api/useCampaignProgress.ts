import { useEffect } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';
import { echo } from '@/shared/lib/echo';
import { campaignProgressSchema } from '../schemas/campaignProgress';
import { campaignsListKey } from './useCampaigns';

// Leading dot: the server sets a custom broadcastAs() name, so Echo must not prefix the app namespace.
export const CAMPAIGN_PROGRESS_EVENT = '.campaign.progress';

type CachedRow = { id: number; status: string; [key: string]: unknown };
type CachedPage = { data: CachedRow[]; [key: string]: unknown };

type ConnectionState = { previous: string; current: string };
type PusherConnection = {
    bind: (event: string, callback: (state: ConnectionState) => void) => void;
    unbind: (event: string, callback: (state: ConnectionState) => void) => void;
};

// Live campaign progress (9.4d, 6.0 pattern): patches the affected row in every cached list page and
// invalidates only when the list could now be wrong (a status change moves a row between filters).
// The socket carries counters only; the server stays the source of truth, so a reconnect refetches.
export function useCampaignProgress(tenantId: string | number) {
    const queryClient = useQueryClient();

    useEchoChannel(`tenant.${tenantId}.campaigns`, CAMPAIGN_PROGRESS_EVENT, (raw) => {
        const parsed = campaignProgressSchema.safeParse(raw);
        if (!parsed.success) return;
        const p = parsed.data;

        let found = false;
        let statusChanged = false;

        queryClient.setQueriesData<CachedPage>({ queryKey: campaignsListKey(tenantId) }, (old) => {
            if (!old || !Array.isArray(old.data)) return old;
            return {
                ...old,
                data: old.data.map((row) => {
                    if (row.id !== p.id) return row;
                    found = true;
                    if (row.status !== p.status) statusChanged = true;
                    return {
                        ...row,
                        status: p.status,
                        lock_version: p.lock_version,
                        recipients_count: p.recipients_count,
                        sent_count: p.sent_count,
                        failed_count: p.failed_count,
                    };
                }),
            };
        });

        if (statusChanged || (!found && p.status !== 'sending')) {
            void queryClient.invalidateQueries({ queryKey: campaignsListKey(tenantId) });
        }
    });

    // Reconnection (6.0): events missed while the socket was down are recovered by refetching.
    useEffect(() => {
        const connection = (echo.connector as unknown as { pusher?: { connection?: PusherConnection } }).pusher
            ?.connection;
        if (!connection) return;

        const onStateChange = (state: ConnectionState) => {
            if (state.current === 'connected' && state.previous !== 'connecting' && state.previous !== 'initialized') {
                void queryClient.invalidateQueries({ queryKey: campaignsListKey(tenantId) });
            }
        };

        connection.bind('state_change', onStateChange);
        return () => connection.unbind('state_change', onStateChange);
    }, [queryClient, tenantId]);
}