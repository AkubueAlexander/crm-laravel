import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { dealSchema, type Deal, type DealStage } from '../schemas/deal';

type MoveDealStageInput = {
    dealId: Deal['id'];
    toStage: DealStage;
    lockVersion: number;
};

/**
 * 3.2: optimistic update — immediately patch the dragged card into its new
 * column, then reconcile against the real response. On a 409 (someone else
 * moved it first — the concurrency case), roll back the optimistic patch;
 * the caller surfaces the "updated by someone else" message via onError.
 */
export function useMoveDealStage(boardId: number = 1) {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const queryKey = ['deals', tenant?.id, boardId] as const;

    return useMutation({
        mutationFn: async ({ dealId, toStage, lockVersion }: MoveDealStageInput): Promise<Deal> => {
            const { data } = await apiClient.post(`/deals/${dealId}/transition`, {
                to_stage: toStage,
                lock_version: lockVersion,
            });
            return dealSchema.parse(data);
        },
        onMutate: async (vars) => {
            await queryClient.cancelQueries({ queryKey });
            const previous = queryClient.getQueryData<Deal[]>(queryKey);

            queryClient.setQueryData<Deal[]>(queryKey, (old) =>
                old?.map((d) => (d.id === vars.dealId ? { ...d, stage: vars.toStage } : d)) ?? [],
            );

            return { previous };
        },
        onError: (_err, _vars, context) => {
            if (context?.previous) {
                queryClient.setQueryData(queryKey, context.previous);
            }
        },
        onSettled: () => {
            // Reconcile against the server regardless of outcome — the
            // optimistic patch above is a UI-responsiveness measure only.
            queryClient.invalidateQueries({ queryKey });
        },
    });
}

export function isStaleDealError(err: unknown): boolean {
    return isApiError(err) && err.response?.status === 409;
}
