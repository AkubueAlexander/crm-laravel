import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTenant } from '@/shared/hooks/useTenant';
import { quoteSchema, type Quote, type QuotesPage, type QuoteStatus } from '../schemas/quote';
import { quotesListKey, quotesRootKey } from './useQuotes';

export type TransitionQuoteVars = {
    quote: Quote;
    toStatus: QuoteStatus;
    // Required by the server when rejecting; optional otherwise.
    note?: string;
};

type Context = {
    previousLists: Array<[QueryKey, QuotesPage | undefined]>;
    previousDetail: Quote | undefined;
};

function patchRow(page: QuotesPage | undefined, id: number, fn: (q: Quote) => Quote): QuotesPage | undefined {
    if (!page) return page;
    return { ...page, data: page.data.map((q) => (q.id === id ? fn(q) : q)) };
}

export const STALE_QUOTE_MESSAGE = 'This quote was updated by someone else - the latest version is shown now.';

export function isStaleQuoteError(err: unknown): boolean {
    return isApiError(err) && err.response?.status === 409;
}

// 409 = lock_version mismatch (3.0 pattern). 422 (invalid_transition, quote_empty, note_required) carries the
// server's own sentence, which apiErrorMessage prefers.
export function transitionQuoteErrorMessage(err: unknown): string {
    if (isStaleQuoteError(err)) return STALE_QUOTE_MESSAGE;
    return apiErrorMessage(err, 'Could not update the quote. Please try again.');
}

// 3.0 pattern: optimistic patch of every cached list page and the detail, roll back on any error, reconcile with
// the server's copy (the response, or `current` on a 409), then invalidate. Submit may come back already approved
// (auto-approval), so the response always wins over the optimistic status.
export function useTransitionQuote() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const listKey = quotesListKey(tenant?.id);

    function detailKey(id: number) {
        return [...quotesRootKey(tenant?.id), 'detail', id] as const;
    }

    function applyServerCopy(server: Quote) {
        // List rows never carry lines; keep their lines_count.
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { lines: _lines, ...rest } = server;
        queryClient.setQueriesData<QuotesPage>({ queryKey: listKey }, (old) =>
            patchRow(old, server.id, (q) => ({ ...q, ...rest, lines_count: q.lines_count })),
        );
        queryClient.setQueryData<Quote>(detailKey(server.id), (old) => (old ? { ...old, ...server } : old));
    }

    return useMutation<Quote, unknown, TransitionQuoteVars, Context>({
        mutationFn: async ({ quote, toStatus, note }) => {
            const { data } = await apiClient.post(`/quotes/${quote.id}/transition`, {
                to_status: toStatus,
                lock_version: quote.lock_version,
                ...(note ? { note } : {}),
            });
            return quoteSchema.parse(data);
        },
        onMutate: async (vars) => {
            const key = detailKey(vars.quote.id);

            await queryClient.cancelQueries({ queryKey: listKey });
            await queryClient.cancelQueries({ queryKey: key });

            const previousLists = queryClient.getQueriesData<QuotesPage>({ queryKey: listKey });
            const previousDetail = queryClient.getQueryData<Quote>(key);

            queryClient.setQueriesData<QuotesPage>({ queryKey: listKey }, (old) =>
                patchRow(old, vars.quote.id, (q) => ({ ...q, status: vars.toStatus })),
            );
            if (previousDetail) {
                queryClient.setQueryData<Quote>(key, { ...previousDetail, status: vars.toStatus });
            }

            return { previousLists, previousDetail };
        },
        onError: (err, vars, context) => {
            context?.previousLists.forEach(([key, data]) => queryClient.setQueryData(key, data));
            if (context?.previousDetail) {
                queryClient.setQueryData(detailKey(vars.quote.id), context.previousDetail);
            }

            // 409: someone else moved it first. The response carries their version: show it right away.
            if (isStaleQuoteError(err) && isApiError(err)) {
                const current = quoteSchema.safeParse((err.response?.data as { current?: unknown } | undefined)?.current);
                if (current.success) applyServerCopy(current.data);
            }
        },
        onSuccess: (server) => applyServerCopy(server),
        onSettled: () => queryClient.invalidateQueries({ queryKey: quotesRootKey(tenant?.id) }),
    });
}
