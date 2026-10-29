import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTenant } from '@/shared/hooks/useTenant';
import { leaveRequestSchema, type LeaveFormValues, type LeaveRequest, type LeaveRequestsPage, type LeaveStatus } from '../schemas/leave';
import { leaveRootKey } from './useLeave';

export function leaveErrorMessage(err: unknown, fallback: string): string {
    if (isApiError(err) && err.response?.status === 409) return err.response.data.message;
    return apiErrorMessage(err, fallback);
}

// 409 carries the current request ({ code: 'stale_leave_request', current }).
function staleCurrent(err: unknown): LeaveRequest | null {
    if (!isApiError(err) || err.response?.status !== 409) return null;
    const parsed = leaveRequestSchema.safeParse((err.response?.data as { current?: unknown } | undefined)?.current);
    return parsed.success ? parsed.data : null;
}

function replaceRow(page: LeaveRequestsPage | undefined, next: LeaveRequest): LeaveRequestsPage | undefined {
    if (!page) return page;
    return { ...page, data: page.data.map((r) => (r.id === next.id ? next : r)) };
}

export type SaveLeaveVars = { requestId?: number; lockVersion?: number; values: LeaveFormValues };

// NOT optimistic: overlap checks and ownership are decided by the server. Editing sends lock_version (409 when stale).
export function useSaveLeaveRequest() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    return useMutation({
        mutationFn: async ({ requestId, lockVersion, values }: SaveLeaveVars): Promise<LeaveRequest> => {
            const reason = values.reason.trim() === '' ? null : values.reason.trim();
            const base = { type: values.type, start_date: values.start_date, end_date: values.end_date, reason };
            const { data } = requestId
                ? await apiClient.put(`/hr/leave-requests/${requestId}`, { ...base, lock_version: lockVersion })
                : await apiClient.post('/hr/leave-requests', { ...base, employee_id: Number(values.employee_id) });
            return leaveRequestSchema.parse(data);
        },
        onSuccess: () => void queryClient.invalidateQueries({ queryKey: leaveRootKey(tenant?.id) }),
    });
}

export type TransitionLeaveVars = { id: number; toStatus: LeaveStatus; lockVersion: number; note?: string };

// State-machine pattern (3.0): optimistic status change on every cached list page, rolled back on failure.
// On a 409 the server's current request replaces the row instead of the stale snapshot.
export function useTransitionLeaveRequest() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    return useMutation({
        mutationFn: async ({ id, toStatus, lockVersion, note }: TransitionLeaveVars): Promise<LeaveRequest> => {
            const { data } = await apiClient.post(`/hr/leave-requests/${id}/transition`, {
                to_status: toStatus,
                lock_version: lockVersion,
                ...(note ? { note } : {}),
            });
            return leaveRequestSchema.parse(data);
        },
        onMutate: async (vars) => {
            const root = leaveRootKey(tenant?.id);
            await queryClient.cancelQueries({ queryKey: root });
            const snapshots = queryClient.getQueriesData<LeaveRequestsPage>({ queryKey: root });
            queryClient.setQueriesData<LeaveRequestsPage>({ queryKey: root }, (old) =>
                old
                    ? {
                          ...old,
                          data: old.data.map((r) =>
                              r.id === vars.id ? { ...r, status: vars.toStatus, lock_version: r.lock_version + 1, decision_note: vars.note ?? r.decision_note } : r,
                          ),
                      }
                    : old,
            );
            return { snapshots, root };
        },
        onError: (err, _vars, ctx) => {
            if (!ctx) return;
            const current = staleCurrent(err);
            if (current) {
                queryClient.setQueriesData<LeaveRequestsPage>({ queryKey: ctx.root }, (old) => replaceRow(old, current));
            } else {
                for (const [key, data] of ctx.snapshots) queryClient.setQueryData(key, data);
            }
        },
        onSuccess: (updated, _vars, ctx) => {
            if (ctx) queryClient.setQueriesData<LeaveRequestsPage>({ queryKey: ctx.root }, (old) => replaceRow(old, updated));
        },
        onSettled: () => void queryClient.invalidateQueries({ queryKey: leaveRootKey(tenant?.id) }),
    });
}
