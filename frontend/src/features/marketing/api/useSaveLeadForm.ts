import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadFormSchema, type LeadFormPayload } from '../schemas/leadForm';

type SaveVars = { id: number | null; payload: LeadFormPayload };

/** Create (id null) and update share one hook, like the API's one write path. */
export function useSaveLeadForm() {
  const queryClient = useQueryClient();
  const { tenant } = useTenant();
  const tenantId = tenant?.id ?? null;

  return useMutation({
    mutationFn: async ({ id, payload }: SaveVars) => {
      const response =
        id === null ? await apiClient.post('/lead-forms', payload) : await apiClient.put(`/lead-forms/${id}`, payload);
      return leadFormSchema.parse(response.data);
    },
    onSuccess: () => queryClient.invalidateQueries({ queryKey: ['lead-forms', tenantId] }),
  });
}