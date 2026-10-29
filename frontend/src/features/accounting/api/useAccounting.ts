import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    journalEntryPageSchema,
    journalEntrySchema,
    ledgerAccountListSchema,
    type JournalEntry,
    type JournalEntryPage,
    type LedgerAccount,
} from '../schemas/accounting';

// Every accounting query key is namespaced by tenant (1.2).
export const accountingRootKey = (tenantId?: string | number) => ['accounting', tenantId] as const;

export function useLedgerAccounts(enabled = true) {
    const { tenant } = useTenant();
    return useQuery({
        queryKey: [...accountingRootKey(tenant?.id), 'accounts'],
        queryFn: async (): Promise<LedgerAccount[]> => {
            const { data } = await apiClient.get('/accounting/accounts');
            const parsed = ledgerAccountListSchema.parse(data);
            return Array.isArray(parsed) ? parsed : parsed.data;
        },
        enabled: enabled && !!tenant,
    });
}

export type JournalListParams = {
    page: number;
    q?: string;
    accountId?: number;
    from?: string;
    to?: string;
    perPage?: number;
    enabled?: boolean;
};

export function useJournalEntries({ page, q, accountId, from, to, perPage = 25, enabled = true }: JournalListParams) {
    const { tenant } = useTenant();
    return useQuery({
        queryKey: [...accountingRootKey(tenant?.id), 'journal', { page, q, accountId, from, to, perPage }],
        queryFn: async (): Promise<JournalEntryPage> => {
            const { data } = await apiClient.get('/accounting/journal-entries', {
                params: {
                    page,
                    per_page: perPage,
                    ...(q ? { q } : {}),
                    ...(accountId ? { account_id: accountId } : {}),
                    ...(from ? { from } : {}),
                    ...(to ? { to } : {}),
                },
            });
            return journalEntryPageSchema.parse(data);
        },
        placeholderData: keepPreviousData,
        enabled: enabled && !!tenant,
    });
}

export function useJournalEntry(entryId: number | null) {
    const { tenant } = useTenant();
    return useQuery({
        queryKey: [...accountingRootKey(tenant?.id), 'entry', entryId],
        queryFn: async (): Promise<JournalEntry> => {
            const { data } = await apiClient.get(`/accounting/journal-entries/${entryId}`);
            return journalEntrySchema.parse(data);
        },
        enabled: entryId !== null && !!tenant,
    });
}
