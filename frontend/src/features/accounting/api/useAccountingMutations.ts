import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    journalEntrySchema,
    ledgerAccountSchema,
    toCents,
    type AccountFormValues,
    type JournalEntry,
    type JournalFormValues,
    type LedgerAccount,
} from '../schemas/accounting';
import { accountingRootKey } from './useAccounting';

// Field messages from a 422 ({ errors: { 'lines.0.debit': [..] } }), first message per field. Empty for any other error.
export function accountingFieldErrors(err: unknown): Record<string, string> {
    if (!isApiError(err) || err.response?.status !== 422) return {};
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') out[field] = messages[0];
    }
    return out;
}

function useInvalidateAccounting() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    return () => void queryClient.invalidateQueries({ queryKey: accountingRootKey(tenant?.id) });
}

function twoDecimals(value: string): string {
    const cents = toCents(value) ?? 0;
    return (cents / 100).toFixed(2);
}

export type SaveAccountVars = { accountId?: number; values: AccountFormValues };

// NOT optimistic: the server owns the chart (code/type are frozen once an account has postings).
export function useSaveLedgerAccount() {
    const invalidate = useInvalidateAccounting();
    return useMutation({
        mutationFn: async ({ accountId, values }: SaveAccountVars): Promise<LedgerAccount> => {
            const body = { code: values.code.trim().toUpperCase(), name: values.name.trim(), type: values.type, is_active: values.is_active };
            const { data } = accountId ? await apiClient.put(`/accounting/accounts/${accountId}`, body) : await apiClient.post('/accounting/accounts', body);
            return ledgerAccountSchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export function useLoadDefaultChart() {
    const invalidate = useInvalidateAccounting();
    return useMutation({
        mutationFn: async (): Promise<number> => {
            const { data } = await apiClient.post('/accounting/accounts/defaults');
            const created = (data as { created?: unknown } | undefined)?.created;
            return typeof created === 'number' ? created : 0;
        },
        onSuccess: invalidate,
    });
}

// NOT optimistic: the journal is append-only and the server decides the entry number and validates the balance.
export function usePostJournalEntry() {
    const invalidate = useInvalidateAccounting();
    return useMutation({
        mutationFn: async (values: JournalFormValues): Promise<JournalEntry> => {
            const body = {
                entry_date: values.entry_date,
                memo: values.memo.trim(),
                lines: values.lines.map((l) => ({
                    account_id: Number(l.account_id),
                    ...(l.description.trim() ? { description: l.description.trim() } : {}),
                    debit: twoDecimals(l.debit),
                    credit: twoDecimals(l.credit),
                })),
            };
            const { data } = await apiClient.post('/accounting/journal-entries', body);
            return journalEntrySchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export type ReverseVars = { entryId: number; reason: string; entryDate?: string };

// NOT optimistic: a reversal is a new append-only entry created by the server.
export function useReverseJournalEntry() {
    const invalidate = useInvalidateAccounting();
    return useMutation({
        mutationFn: async ({ entryId, reason, entryDate }: ReverseVars): Promise<JournalEntry> => {
            const { data } = await apiClient.post(`/accounting/journal-entries/${entryId}/reverse`, {
                reason,
                ...(entryDate ? { entry_date: entryDate } : {}),
            });
            return journalEntrySchema.parse(data);
        },
        onSuccess: invalidate,
    });
}
