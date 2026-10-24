import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { tenantSchema, type CurrentUser, type Tenant } from '@/features/auth/schemas/auth';
import type { BrandingFormInput } from '../schemas/settings';

// Multipart POST: PHP does not parse multipart bodies on PUT (see the 8c.1a route comment).
export function useUpdateBranding() {
    const queryClient = useQueryClient();

    return useMutation({
        mutationFn: async (values: BrandingFormInput): Promise<Tenant> => {
            const body = new FormData();
            // Always sent: an empty string clears the accent color server-side.
            body.append('primary_color', values.primary_color);

            const file = values.logo;
            if (file) {
                body.append('logo', file);
            } else if (values.remove_logo) {
                body.append('remove_logo', '1');
            }

            const { data } = await apiClient.post('/settings/branding', body);
            return tenantSchema.parse(data);
        },
        onSuccess: (tenant) => {
            // The tenant lives in the ['auth', 'me'] cache (1.1): patch it so the shell updates at
            // once, then re-read to stay authoritative.
            queryClient.setQueryData<CurrentUser>(['auth', 'me'], (old) => (old ? { ...old, tenant } : old));
            void queryClient.invalidateQueries({ queryKey: ['auth', 'me'] });
        },
    });
}
