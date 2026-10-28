import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTenant } from '@/shared/hooks/useTenant';
import { invoiceSchema, type Invoice, type InvoiceStatus, type InvoicesPage } from '../schemas/invoice';
import { invoiceDetailKey, invoicesListKey, invoicesRootKey } from './useInvoices';

export type CreateInvoiceVars = { quoteId: number; paymentTermsDays?: number };

// Not optimistic: the server allocates the number and snapshots the quote. Tenant comes from the session (1.3).
export function useCreateInvoice() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ quoteId, paymentTermsDays }: CreateInvoiceVars): Promise<Invoice> => {
            const { data } = await apiClient.post('/invoices', {
                quote_id: quoteId,
                ...(paymentTermsDays !== undefined ? { payment_terms_days: paymentTermsDays } : {}),
            });
            return invoiceSchema.parse(data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: invoicesRootKey(tenant?.id) }),
    });
}

export type TransitionInvoiceVars = {
    invoice: Invoice;
    toStatus: InvoiceStatus;
    // Required by the server when voiding; optional otherwise.
    note?: string;
};

type Context = {
    previousLists: Array<[QueryKey, InvoicesPage | undefined]>;
    previousDetail: Invoice | undefined;
};

function patchRow(page: InvoicesPage | undefined, id: number, fn: (i: Invoice) => Invoice): InvoicesPage | undefined {
    if (!page) return page;
    return { ...page, data: page.data.map((i) => (i.id === id ? fn(i) : i)) };
}

export const STALE_INVOICE_MESSAGE = 'This invoice was updated by someone else - the latest version is shown now.';

export function isStaleInvoiceError(err: unknown): boolean {
    return isApiError(err) && err.response?.status === 409;
}

// 409 = lock_version mismatch (3.0 pattern). 422 (invalid_transition, note_required) carries the server's own
// sentence, which apiErrorMessage prefers.
export function transitionInvoiceErrorMessage(err: unknown): string {
    if (isStaleInvoiceError(err)) return STALE_INVOICE_MESSAGE;
    return apiErrorMessage(err, 'Could not update the invoice. Please try again.');
}

// 3.0 pattern: optimistic patch of every cached list page and the detail, roll back on any error, reconcile with
// the server's copy (the response, or `current` on a 409), then invalidate.
export function useTransitionInvoice() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const listKey = invoicesListKey(tenant?.id);

    function applyServerCopy(server: Invoice) {
        // List rows never carry lines; keep their lines_count.
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { lines: _lines, ...rest } = server;
        queryClient.setQueriesData<InvoicesPage>({ queryKey: listKey }, (old) =>
            patchRow(old, server.id, (i) => ({ ...i, ...rest, lines_count: i.lines_count })),
        );
        queryClient.setQueryData<Invoice>(invoiceDetailKey(tenant?.id, server.id), (old) => (old ? { ...old, ...server } : old));
    }

    return useMutation<Invoice, unknown, TransitionInvoiceVars, Context>({
        mutationFn: async ({ invoice, toStatus, note }) => {
            const { data } = await apiClient.post(`/invoices/${invoice.id}/transition`, {
                to_status: toStatus,
                lock_version: invoice.lock_version,
                ...(note ? { note } : {}),
            });
            return invoiceSchema.parse(data);
        },
        onMutate: async (vars) => {
            const key = invoiceDetailKey(tenant?.id, vars.invoice.id);

            await queryClient.cancelQueries({ queryKey: listKey });
            await queryClient.cancelQueries({ queryKey: key });

            const previousLists = queryClient.getQueriesData<InvoicesPage>({ queryKey: listKey });
            const previousDetail = queryClient.getQueryData<Invoice>(key);

            queryClient.setQueriesData<InvoicesPage>({ queryKey: listKey }, (old) =>
                patchRow(old, vars.invoice.id, (i) => ({ ...i, status: vars.toStatus })),
            );
            if (previousDetail) {
                queryClient.setQueryData<Invoice>(key, { ...previousDetail, status: vars.toStatus });
            }

            return { previousLists, previousDetail };
        },
        onError: (err, vars, context) => {
            context?.previousLists.forEach(([key, data]) => queryClient.setQueryData(key, data));
            if (context?.previousDetail) {
                queryClient.setQueryData(invoiceDetailKey(tenant?.id, vars.invoice.id), context.previousDetail);
            }

            // 409: someone else moved it first. The response carries their version: show it right away.
            if (isStaleInvoiceError(err) && isApiError(err)) {
                const current = invoiceSchema.safeParse((err.response?.data as { current?: unknown } | undefined)?.current);
                if (current.success) applyServerCopy(current.data);
            }
        },
        onSuccess: (server) => applyServerCopy(server),
        onSettled: () => queryClient.invalidateQueries({ queryKey: invoicesRootKey(tenant?.id) }),
    });
}
