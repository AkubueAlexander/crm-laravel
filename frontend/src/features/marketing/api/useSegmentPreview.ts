import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import type { SegmentDefinition } from '@/features/marketing/schemas/segmentForm';

const previewSchema = z.object({
  data: z.object({
    count: z.number().int().nonnegative(),
    // Only returned to users with contacts.view.
    sample: z
      .array(z.object({ id: z.number(), name: z.string().nullable(), email: z.string().nullable() }))
      .optional()
      .default([]),
  }),
});

export type SegmentPreview = z.infer<typeof previewSchema>['data'];

/** definition === null keeps the query idle (the form's conditions do not validate yet). */
export function useSegmentPreview(definition: SegmentDefinition | null) {
  const tenantId = useTenantId();
  return useQuery<SegmentPreview>({
    queryKey: ['segments', tenantId, 'preview', definition],
    queryFn: async () => {
      if (definition === null) throw new Error('No definition to preview');
      const response = await apiClient.post('/segments/preview', definition);
      return previewSchema.parse(response.data).data;
    },
    enabled: definition !== null,
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    retry: false,
  });
}