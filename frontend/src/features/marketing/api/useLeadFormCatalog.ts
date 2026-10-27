import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadFormCatalogSchema } from '../schemas/leadForm';

export function useLeadFormCatalog() {
  const { tenant } = useTenant();
  const tenantId = tenant?.id ?? null;

  return useQuery({
    queryKey: ['lead-forms', tenantId, 'catalog'],
    enabled: tenantId !== null,
    staleTime: 5 * 60 * 1000,
    queryFn: async () => {
      const { data } = await apiClient.get('/lead-forms/catalog');
      return leadFormCatalogSchema.parse(data);
    },
  });
}