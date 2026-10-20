import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';

interface ConvertLeadResult {
  contact: { id: number; name: string };
  deal: { id: number; name: string; state: string };
}

/**
 * 8a.2 â€” POST /api/v1/leads/{lead}/convert
 * Invalidates leads, contacts, and deals lists on success, since a
 * successful conversion changes all three (mirrors 8a.2's spec exactly).
 */
export function useConvertLead(leadId: number) {
  const queryClient = useQueryClient();
  const { tenantId } = useTenant();

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