import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import { segmentDetailKey } from '@/features/marketing/api/useSegment';
import { segmentsListKey } from '@/features/marketing/api/useSegments';
import { segmentSchema } from '@/features/marketing/schemas/segment';
import type { SegmentPayload } from '@/features/marketing/schemas/segmentForm';

// Not optimistic: a segment definition is validated server-side (SegmentDsl) and a save can
// legitimately fail with 422, so the UI waits for the real response. Updates are last-write-wins.

export function useCreateSegment() {
  const queryClient = useQueryClient();
  const tenantId = useTenantId();
  return useMutation({
    mutationFn: async (payload: SegmentPayload) => {
      const response = await apiClient.post('/segments', payload);
      return segmentSchema.parse(response.data);
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: segmentsListKey(tenantId) }),
  });
}

export function useUpdateSegment(id: number) {
  const queryClient = useQueryClient();
  const tenantId = useTenantId();
  return useMutation({
    mutationFn: async (payload: SegmentPayload) => {
      const response = await apiClient.put(`/segments/${id}`, payload);
      return segmentSchema.parse(response.data);
    },
    onSettled: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: segmentsListKey(tenantId) }),
        queryClient.invalidateQueries({ queryKey: segmentDetailKey(tenantId, id) }),
      ]);
    },
  });
}