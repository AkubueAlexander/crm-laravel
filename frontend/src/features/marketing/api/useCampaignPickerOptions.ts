import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';

const optionsSchema = z.object({ data: z.array(z.object({ id: z.number(), name: z.string() })) });

export type PickerOption = { id: number; name: string };

// 9.4e: capped id+name lists for the campaign form's <select>s. Tenant-namespaced keys (1.2); the list
// endpoints page at 15/25 rows, which is the wrong shape for a picker.
function usePickerOptions(kind: 'segments' | 'email-templates', path: string) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: [kind, tenant?.id, 'options'] as const,
        queryFn: async (): Promise<PickerOption[]> => {
            const { data } = await apiClient.get(path);
            return optionsSchema.parse(data).data;
        },
        enabled: !!tenant?.id,
    });
}

export function useSegmentOptions() {
    return usePickerOptions('segments', '/campaigns/segment-options');
}

export function useTemplateOptions() {
    return usePickerOptions('email-templates', '/campaigns/template-options');
}