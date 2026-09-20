import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { forecastResultSchema, type ForecastResult } from '../schemas/forecast';

// 4.2: tenant-namespaced query key, same convention as every other
// server-state hook in the app (1.2).
export function useForecast() {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: ['forecast', tenant?.id] as const,
        queryFn: async (): Promise<ForecastResult> => {
            const { data } = await apiClient.get('/forecast');
            return forecastResultSchema.parse(data);
        },
        enabled: !!tenant?.id,
    });
}
