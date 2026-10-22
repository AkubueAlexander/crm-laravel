import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';

interface ConvertLeadResult {
  contact: { id: number; name: string };
  deal: { id: number; name: string; state: string };
}


export function useConvertLead(leadId: number) {
  const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const tenantId = tenant?.id;

  return useMutation({
    mutationFn: () =>
      apiClient
        .post<ConvertLeadResult>(`/api/v1/leads/${leadId}/convert`)
        .then((res) => res.data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['leads', tenantId] });
      queryClient.invalidateQueries({ queryKey: ['contacts', tenantId] });
      queryClient.invalidateQueries({ queryKey: ['deals', tenantId] });
    },
  });
}
