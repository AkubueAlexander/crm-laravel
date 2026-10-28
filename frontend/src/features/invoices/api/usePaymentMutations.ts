import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { invoiceSchema, type Invoice } from '../schemas/invoice';
import type { PaymentFormValues } from '../schemas/payment';
import { invoiceDetailKey, invoicesRootKey } from './useInvoices';

// Field messages from a 422 ({ errors: { amount: [..] } }), first message per field. Empty for any other error.
export function paymentFieldErrors(err: unknown): Record<string, string> {
    if (!isApiError(err) || err.response?.status !== 422) return {};
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') out[field] = messages[0];
    }
    return out;
}

// NOT optimistic: the server decides (balance, overpayment, status), and every payment bumps lock_version.
// The response (invoice + payments) is merged into the detail cache, then the whole invoice tree is refetched.
function useApplyInvoice() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return (server: Invoice) => {
        queryClient.setQueryData<Invoice>(invoiceDetailKey(tenant?.id, server.id), (old) => (old ? { ...old, ...server } : old));
        void queryClient.invalidateQueries({ queryKey: invoicesRootKey(tenant?.id) });
    };
}

export type RecordPaymentVars = { invoiceId: number; values: PaymentFormValues };

export function useRecordPayment() {
    const apply = useApplyInvoice();

    return useMutation({
        mutationFn: async ({ invoiceId, values }: RecordPaymentVars): Promise<Invoice> => {
            const { data } = await apiClient.post(`/invoices/${invoiceId}/payments`, {
                amount: values.amount,
                method: values.method,
                paid_on: values.paid_on,
                ...(values.reference ? { reference: values.reference } : {}),
                ...(values.note ? { note: values.note } : {}),
            });
            return invoiceSchema.parse(data);
        },
        onSuccess: apply,
    });
}

export type VoidPaymentVars = { paymentId: number; reason: string };

export function useVoidPayment() {
    const apply = useApplyInvoice();

    return useMutation({
        mutationFn: async ({ paymentId, reason }: VoidPaymentVars): Promise<Invoice> => {
            const { data } = await apiClient.post(`/payments/${paymentId}/void`, { reason });
            return invoiceSchema.parse(data);
        },
        onSuccess: apply,
    });
}
