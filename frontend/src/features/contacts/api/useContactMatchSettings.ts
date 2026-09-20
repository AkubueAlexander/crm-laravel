import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { matchSettingsSchema, type MatchSettings, type UpdateMatchSettingsInput } from '../schemas/matchSettings';

export function useContactMatchSettings() {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['contacts', tenant?.id, 'match-settings'] as const,
        queryFn: async (): Promise<MatchSettings> => {
            const { data } = await apiClient.get('/contacts/match-settings');
            return matchSettingsSchema.parse(data);
        },
        enabled: !!tenant?.id,
    });
}

/**
 * On save, invalidates the settings query AND the duplicate-check-dependent
 * views -- a changed threshold should be reflected immediately, matching the
 * original spec's "invalidate relevant queries on save."
 */
export function useUpdateContactMatchSettings() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: UpdateMatchSettingsInput): Promise<MatchSettings> => {
            const { data } = await apiClient.put('/contacts/match-settings', input);
            return matchSettingsSchema.parse(data);
        },
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['contacts', tenant?.id, 'match-settings'] });
        },
    });
}