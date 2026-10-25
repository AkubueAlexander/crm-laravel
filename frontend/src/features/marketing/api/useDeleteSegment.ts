import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { segmentsListKey } from './useSegments';

// Not optimistic on purpose: the user confirms in a dialog first, and the server may still refuse
// (9.4 will refuse a segment a sent campaign uses), so the row only disappears once the API says it is gone.
export function useDeleteSegment() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (segmentId: number): Promise<void> => {
            await apiClient.delete(`/segments/${segmentId}`);
        },
        // Also on error: a 404 or a refusal usually means the list on screen is stale.
        onSettled: () => queryClient.invalidateQueries({ queryKey: segmentsListKey(tenant?.id) }),
    });
}
