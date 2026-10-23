import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { contactImportSchema, type ContactImport } from '../schemas/contactImport';

// Tenant-namespaced (1.2) and under the 'contacts' prefix, so contact mutations
// that invalidate ['contacts', tenantId] also refresh any open import.
export function contactImportKey(tenantId: string | number | undefined, importId: number | null) {
    return ['contacts', tenantId, 'import', importId] as const;
}

// Kept current by useContactImportLive (Reverb), not by polling. Refetches on window focus.
export function useContactImport(importId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: contactImportKey(tenant?.id, importId),
        queryFn: async ({ signal }): Promise<ContactImport> => {
            const { data } = await apiClient.get(`/contacts/imports/${importId}`, { signal });
            return contactImportSchema.parse(data);
        },
        enabled: !!tenant && importId !== null,
        staleTime: 10_000,
    });
}

export function useStartContactImport() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (file: File): Promise<ContactImport> => {
            const body = new FormData();
            body.append('file', file);
            const { data } = await apiClient.post('/contacts/imports', body);
            return contactImportSchema.parse(data);
        },
        onSuccess: (created) => {
            const key = contactImportKey(tenant?.id, created.id);
            queryClient.setQueryData(key, created);
            // The worker may already have progressed before this response arrived.
            void queryClient.invalidateQueries({ queryKey: key });
        },
    });
}
