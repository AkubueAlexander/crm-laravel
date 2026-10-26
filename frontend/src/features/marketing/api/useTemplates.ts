import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import {
  emailTemplatesPageSchema,
  type TemplateSortColumn,
} from '@/features/marketing/schemas/template';

// Every key is namespaced by tenant id (1.2).
export const templatesListKey = (tenantId: number) => ['email-templates', tenantId, 'list'] as const;

type Params = {
  page: number;
  sort: TemplateSortColumn;
  direction: 'asc' | 'desc';
  q?: string;
  enabled?: boolean;
};

export function useTemplates({ page, sort, direction, q, enabled = true }: Params) {
  const tenantId = useTenantId();
  const params = { page, sort, direction, q: q ?? '' };

  return useQuery({
    queryKey: [...templatesListKey(tenantId), params],
    queryFn: async () => {
      const response = await apiClient.get('/email-templates', {
        params: { ...params, q: params.q === '' ? undefined : params.q },
      });
      return emailTemplatesPageSchema.parse(response.data);
    },
    placeholderData: keepPreviousData,
    enabled,
  });
}