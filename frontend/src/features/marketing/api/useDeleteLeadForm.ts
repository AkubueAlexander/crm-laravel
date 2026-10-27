import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';

export function useDeleteLeadForm() {
  const queryClient = useQueryClient();
  const { tenant } = useTenant();
  const tenantId = tenant?.id ?? null;

  return useMutation({
    mutationFn: async (id: number) => {
      await apiClient.delete(`/lead-forms/${id}`);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lead-forms', tenantId] }),
  });
}