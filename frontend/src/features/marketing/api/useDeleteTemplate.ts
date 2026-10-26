import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, useTenantId } from '@/features/marketing/api/segmentDeps';
import { templatesListKey } from '@/features/marketing/api/useTemplates';

// Not optimistic: the API is the authority (it will refuse deleting a template in use once 9.4 lands).
export function useDeleteTemplate() {
  const queryClient = useQueryClient();
  const tenantId = useTenantId();

  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/email-templates/${id}`);
    },
    onSettled: () => queryClient.invalidateQueries({ queryKey: templatesListKey(tenantId) }),
  });
}