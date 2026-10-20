import { useMutation } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { duplicateCheckResponseSchema, type DuplicateCheckInput, type DuplicateCheckResponse } from '../schemas/contact';


export function useDuplicateCheck() {
    return useMutation({
        mutationFn: async (input: DuplicateCheckInput): Promise<DuplicateCheckResponse> => {
            const { data } = await apiClient.post('/contacts/check-duplicates', input);
            return duplicateCheckResponseSchema.parse(data);
        },
    });
}
