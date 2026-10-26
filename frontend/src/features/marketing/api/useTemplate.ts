import { useQuery } from '@tanstack/react-query';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import {
  emailTemplateDetailSchema,
  type EmailTemplateDetail,
} from '@/features/marketing/schemas/template';

export const templateDetailKey = (tenantId: number, id: number) =>
  ['email-templates', tenantId, 'detail', id] as const;

/** Pass null for the "new template" route: the query stays disabled. */
export function useTemplate(id: number | null) {
  const tenantId = useTenantId();
  return useQuery<EmailTemplateDetail>({
    queryKey: templateDetailKey(tenantId, id ?? 0),
    queryFn: async () => {
      const response = await apiClient.get(`/email-templates/${id}`);
      return emailTemplateDetailSchema.parse(response.data);
    },
    enabled: id !== null,
  });
}