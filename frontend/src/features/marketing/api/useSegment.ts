import { useQuery } from '@tanstack/react-query';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import { segmentSchema, type Segment } from '@/features/marketing/schemas/segment';

export const segmentDetailKey = (tenantId: number, id: number) =>
  ['segments', tenantId, 'detail', id] as const;

/** Pass null for the "new segment" route: the query stays disabled. */
export function useSegment(id: number | null) {
  const tenantId = useTenantId();
  return useQuery<Segment>({
    queryKey: segmentDetailKey(tenantId, id ?? 0),
    queryFn: async () => {
      const response = await apiClient.get(`/segments/${id}`);
      return segmentSchema.parse(response.data);
    },
    enabled: id !== null,
  });
}