import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';

const previewSchema = z.object({
  data: z.object({
    subject: z.string(),
    html: z.string(),
    text: z.string(),
    unknown_variables: z.array(z.string()),
  }),
});

export type TemplatePreview = z.infer<typeof previewSchema>['data'];

/**
 * Rendered by the API through the same TemplateRenderer the send job uses, so the author sees
 * exactly what recipients get. Callers pass debounced values. Never writes anything.
 */
export function useTemplatePreview(subject: string, body: string) {
  const tenantId = useTenantId();
  return useQuery<TemplatePreview>({
    queryKey: ['email-templates', tenantId, 'preview', subject, body],
    queryFn: async () => {
      const response = await apiClient.post('/email-templates/preview', { subject, body });
      return previewSchema.parse(response.data).data;
    },
    placeholderData: keepPreviousData,
    staleTime: 30_000,
    retry: false,
  });
}