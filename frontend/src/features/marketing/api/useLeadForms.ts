import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadFormsPageSchema } from '../schemas/leadForm';

// 1.2: tenant-namespaced key. Everything for lead forms lives under ['lead-forms', tenantId, ...].
export function useLeadForms(page: number) {
  const { tenant } = useTenant();
  const tenantId = tenant?.id ?? null;

  return useQuery({
    queryKey: ['lead-forms', tenantId, 'list', page],
    enabled: tenantId !== null,
    placeholderData: keepPreviousData,
    queryFn: async () => {
      const { data } = await apiClient.get('/lead-forms', { params: { page } });
      return leadFormsPageSchema.parse(data);
    },
  });
}