import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { quoteSchema, type Quote } from '../schemas/quote';
import type { QuoteInput } from '../schemas/quoteForm';
import { quotesRootKey } from './useQuotes';

type SaveVars = { id: number | null; input: QuoteInput };

// Not optimistic: the server computes the number and every total, so the screen shows what the API returned.
// Tenant comes from the session, never from the body (1.3).
export function useSaveQuote() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ id, input }: SaveVars): Promise<Quote> => {
            const { data } = id === null ? await apiClient.post('/quotes', input) : await apiClient.put(`/quotes/${id}`, input);
            return quoteSchema.parse(data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: quotesRootKey(tenant?.id) }),
    });
}

// Not optimistic: only drafts can be deleted and the server decides; also refresh on error (the list may be stale).
export function useDeleteQuote() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (quoteId: number): Promise<void> => {
            await apiClient.delete(`/quotes/${quoteId}`);
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: quotesRootKey(tenant?.id) }),
    });
}
