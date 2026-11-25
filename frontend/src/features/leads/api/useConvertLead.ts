import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { leadSchema, type ConvertLeadPayload, type Lead } from '../schemas/lead';

export type ConvertFailure = {
    status?: number;
    code?: string; // 'already_converted' | 'missing_last_name' | undefined
    message: string;
    fieldErrors?: Record<string, string[]>;
};

export function getConvertFailure(err: unknown): ConvertFailure {
    if (isApiError(err)) {
        const body = err.response?.data as unknown as
            | { message?: string; code?: string; errors?: Record<string, string[]> }
            | undefined;
        const hasFieldErrors = !!body?.errors && Object.keys(body.errors).length > 0;

        return {
            status: err.response?.status,
            code: body?.code,
            message: body?.message ?? 'Could not convert this lead.',
            fieldErrors: hasFieldErrors ? body?.errors : undefined,
        };
    }

    return { message: 'Could not convert this lead. Check your connection and try again.' };
}


const STALE_AFTER_CONVERSION = ['leads', 'contacts', 'accounts', 'deals', 'forecast'] as const;


export function useConvertLead(leadId: Lead['id']) {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: ConvertLeadPayload): Promise<Lead> => {
            const { data } = await apiClient.post(`/leads/${leadId}/convert`, input);
            return leadSchema.parse(data);
        },
        onSuccess: () => {
            for (const key of STALE_AFTER_CONVERSION) {
                queryClient.invalidateQueries({ queryKey: [key, tenant?.id] });
            }
        },
        onError: (err) => {
            if (getConvertFailure(err).status === 409) {
                queryClient.invalidateQueries({ queryKey: ['leads', tenant?.id] });
            }
        },
    });
}
