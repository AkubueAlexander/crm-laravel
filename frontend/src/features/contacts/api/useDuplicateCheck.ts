import { useMutation } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { duplicateCheckResponseSchema, type DuplicateCheckInput, type DuplicateCheckResponse } from '../schemas/contact';

/**
 * 5.1: called on blur/debounce, not on every keystroke. This hook is a plain
 * mutation; the debounce guard lives in ContactForm's blur handler, since blur
 * already gives a natural per-field debounce point — a shared useDebouncedValue
 * hook (8b.1's pattern for search-as-you-type) would be overkill here.
 */
export function useDuplicateCheck() {
    return useMutation({
        mutationFn: async (input: DuplicateCheckInput): Promise<DuplicateCheckResponse> => {
            const { data } = await apiClient.post('/contacts/check-duplicates', input);
            return duplicateCheckResponseSchema.parse(data);
        },
    });
}