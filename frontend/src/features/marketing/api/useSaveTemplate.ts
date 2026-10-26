import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import { templateDetailKey } from '@/features/marketing/api/useTemplate';
import { templatesListKey } from '@/features/marketing/api/useTemplates';
import { emailTemplateDetailSchema } from '@/features/marketing/schemas/template';
import type { TemplatePayload } from '@/features/marketing/schemas/templateForm';

// Not optimistic: a save can legitimately fail with 422 (name taken, unknown variable), so the UI
// waits for the real response. Updates are last-write-wins, like segments.

export function useCreateTemplate() {
  const queryClient = useQueryClient();
  const tenantId = useTenantId();
  return useMutation({
    mutationFn: async (payload: TemplatePayload) => {
      const response = await apiClient.post('/email-templates', payload);
      return emailTemplateDetailSchema.parse(response.data);
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: templatesListKey(tenantId) }),
  });
}

export function useUpdateTemplate(id: number) {
  const queryClient = useQueryClient();
  const tenantId = useTenantId();
  return useMutation({
    mutationFn: async (payload: TemplatePayload) => {
      const response = await apiClient.put(`/email-templates/${id}`, payload);
      return emailTemplateDetailSchema.parse(response.data);
    },
    onSettled: async () => {
      await Promise.all([
        queryClient.invalidateQueries({ queryKey: templatesListKey(tenantId) }),
        queryClient.invalidateQueries({ queryKey: templateDetailKey(tenantId, id) }),
      ]);
    },
  });
}