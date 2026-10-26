import { useQuery } from '@tanstack/react-query';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import {
  templateVariablesResponseSchema,
  type TemplateVariablesResponse,
} from '@/features/marketing/schemas/templateForm';

export function useTemplateVariables() {
  const tenantId = useTenantId();
  return useQuery<TemplateVariablesResponse>({
    queryKey: ['email-templates', tenantId, 'variables'],
    queryFn: async () => {
      const response = await apiClient.get('/email-templates/variables');
      return templateVariablesResponseSchema.parse(response.data);
    },
    staleTime: Infinity,
  });
}