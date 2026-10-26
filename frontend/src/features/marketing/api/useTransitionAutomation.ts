import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTenant } from '@/shared/hooks/useTenant';
import { automationSchema, type Automation, type AutomationsPage, type AutomationStatus } from '../schemas/automation';
import { automationDetailKey } from './useAutomation';
import { automationsListKey } from './useAutomations';

export type TransitionAutomationVars = {
    automation: Automation;
    toStatus: AutomationStatus;
};

type Context = {
    previousLists: Array<[QueryKey, AutomationsPage | undefined]>;
    previousDetail: Automation | undefined;
};

function patchRow(page: AutomationsPage | undefined, id: number, fn: (a: Automation) => Automation): AutomationsPage | undefined {
    if (!page) return page;
    return { ...page, data: page.data.map((a) => (a.id === id ? fn(a) : a)) };
}

export const STALE_AUTOMATION_MESSAGE = 'This automation was updated by someone else - refresh to see the latest version.';

export function isStaleAutomationError(err: unknown): boolean {
    return isApiError(err) && err.response?.status === 409;
}

// 409 = lock_version mismatch (3.0 pattern). 422 (invalid_transition, automation_invalid) carries the server's own
// sentence, which apiErrorMessage prefers.
export function transitionAutomationErrorMessage(err: unknown): string {
    if (isStaleAutomationError(err)) return STALE_AUTOMATION_MESSAGE;
    return apiErrorMessage(err, 'Could not update the automation. Please try again.');
}

// 3.0 pattern: optimistic patch of every cached list page and the detail, roll back on any error, reconcile with the
// server's copy (the response, or `current` on a 409), then invalidate.
export function useTransitionAutomation() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const listKey = automationsListKey(tenant?.id);

    function applyServerCopy(server: Automation) {
        queryClient.setQueriesData<AutomationsPage>({ queryKey: listKey }, (old) => patchRow(old, server.id, () => server));
        queryClient.setQueryData<Automation>(automationDetailKey(tenant?.id, server.id), server);
    }

    return useMutation<Automation, unknown, TransitionAutomationVars, Context>({
        mutationFn: async ({ automation, toStatus }) => {
            const { data } = await apiClient.post(`/automations/${automation.id}/transition`, {
                to_status: toStatus,
                lock_version: automation.lock_version,
            });
            return automationSchema.parse(data);
        },
        onMutate: async (vars) => {
            const detailKey = automationDetailKey(tenant?.id, vars.automation.id);

            await queryClient.cancelQueries({ queryKey: listKey });
            await queryClient.cancelQueries({ queryKey: detailKey });

            const previousLists = queryClient.getQueriesData<AutomationsPage>({ queryKey: listKey });
            const previousDetail = queryClient.getQueryData<Automation>(detailKey);

            queryClient.setQueriesData<AutomationsPage>({ queryKey: listKey }, (old) =>
                patchRow(old, vars.automation.id, (a) => ({ ...a, status: vars.toStatus })),
            );
            if (previousDetail) {
                queryClient.setQueryData<Automation>(detailKey, { ...previousDetail, status: vars.toStatus });
            }

            return { previousLists, previousDetail };
        },
        onError: (err, vars, context) => {
            context?.previousLists.forEach(([key, data]) => queryClient.setQueryData(key, data));
            if (context?.previousDetail) {
                queryClient.setQueryData(automationDetailKey(tenant?.id, vars.automation.id), context.previousDetail);
            }

            // 409: someone else changed it first. The response carries their version: show it right away.
            if (isStaleAutomationError(err) && isApiError(err)) {
                const current = automationSchema.safeParse((err.response?.data as { current?: unknown } | undefined)?.current);
                if (current.success) applyServerCopy(current.data);
            }
        },
        onSuccess: (server) => applyServerCopy(server),
        onSettled: async (_data, _err, vars) => {
            await Promise.all([
                queryClient.invalidateQueries({ queryKey: listKey }),
                queryClient.invalidateQueries({ queryKey: automationDetailKey(tenant?.id, vars.automation.id) }),
            ]);
        },
    });
}