import { useQuery } from '@tanstack/react-query';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import {
  segmentFieldsResponseSchema,
  type SegmentFieldsResponse,
} from '@/features/marketing/schemas/segmentForm';

export const segmentFieldsKey = (tenantId: number) => ['segments', tenantId, 'fields'] as const;

export function useSegmentFields() {
  const tenantId = useTenantId();
  return useQuery<SegmentFieldsResponse>({
    queryKey: segmentFieldsKey(tenantId),
    queryFn: async () => {
      const response = await apiClient.get('/segments/fields');
      return segmentFieldsResponseSchema.parse(response.data);
    },
    staleTime: Infinity,
  });
}