import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { invoiceSchema, invoicesPageSchema, type Invoice, type InvoiceSortColumn, type InvoiceStatus, type InvoicesPage } from '../schemas/invoice';

export type InvoicesListParams = {
    page?: number;
    perPage?: number;
    sort?: InvoiceSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    status?: InvoiceStatus;
    enabled?: boolean;
};

// Tenant-namespaced keys (1.2). The root prefix covers lists and details: mutations invalidate it as a whole.
export function invoicesRootKey(tenantId: unknown) {
    return ['invoices', tenantId] as const;
}

export function invoicesListKey(tenantId: unknown) {
    return [...invoicesRootKey(tenantId), 'list'] as const;
}

export function invoiceDetailKey(tenantId: unknown, invoiceId: number) {
    return [...invoicesRootKey(tenantId), 'detail', invoiceId] as const;
}

export function useInvoices(params: InvoicesListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'invoice_number', direction = 'desc', q = '', status, enabled = true } = params;

    return useQuery({
        queryKey: [...invoicesListKey(tenant?.id), { page, perPage, sort, direction, q, status }] as const,
        queryFn: async (): Promise<InvoicesPage> => {
            const { data } = await apiClient.get('/invoices', {
                params: { page, per_page: perPage, sort, direction, q: q || undefined, status: status || undefined },
            });
            return invoicesPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}

// The list carries lines_count only; the detail page needs the lines, so it loads the detail fresh.
export function useInvoice(invoiceId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: invoiceDetailKey(tenant?.id, invoiceId ?? 0),
        queryFn: async (): Promise<Invoice> => {
            const { data } = await apiClient.get(`/invoices/${invoiceId}`);
            return invoiceSchema.parse(data);
        },
        enabled: !!tenant?.id && invoiceId !== null,
        staleTime: 0,
    });
}
