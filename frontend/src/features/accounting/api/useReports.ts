import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    balanceSheetSchema,
    incomeStatementSchema,
    ledgerReportSchema,
    trialBalanceSchema,
    type BalanceSheet,
    type IncomeStatement,
    type LedgerReport,
    type TrialBalance,
} from '../schemas/reports';
import { accountingRootKey } from './useAccounting';

// Reports live under the accounting root key, so posting or reversing an entry refreshes them.
export function useTrialBalance({ asOf, enabled = true }: { asOf: string; enabled?: boolean }) {
    const { tenant } = useTenant();
    return useQuery({
        queryKey: [...accountingRootKey(tenant?.id), 'reports', 'trial-balance', asOf],
        queryFn: async (): Promise<TrialBalance> => {
            const { data } = await apiClient.get('/accounting/reports/trial-balance', { params: { as_of: asOf } });
            return trialBalanceSchema.parse(data);
        },
        placeholderData: keepPreviousData,
        enabled: enabled && !!tenant,
    });
}

export function useIncomeStatement({ from, to, enabled = true }: { from: string; to: string; enabled?: boolean }) {
    const { tenant } = useTenant();
    return useQuery({
        queryKey: [...accountingRootKey(tenant?.id), 'reports', 'income-statement', from, to],
        queryFn: async (): Promise<IncomeStatement> => {
            const { data } = await apiClient.get('/accounting/reports/income-statement', { params: { from, to } });
            return incomeStatementSchema.parse(data);
        },
        placeholderData: keepPreviousData,
        enabled: enabled && !!tenant,
    });
}

export function useBalanceSheet({ asOf, enabled = true }: { asOf: string; enabled?: boolean }) {
    const { tenant } = useTenant();
    return useQuery({
        queryKey: [...accountingRootKey(tenant?.id), 'reports', 'balance-sheet', asOf],
        queryFn: async (): Promise<BalanceSheet> => {
            const { data } = await apiClient.get('/accounting/reports/balance-sheet', { params: { as_of: asOf } });
            return balanceSheetSchema.parse(data);
        },
        placeholderData: keepPreviousData,
        enabled: enabled && !!tenant,
    });
}

export type LedgerParams = { accountId: number | null; from?: string; to?: string; page: number; perPage?: number; enabled?: boolean };

export function useAccountLedger({ accountId, from, to, page, perPage = 50, enabled = true }: LedgerParams) {
    const { tenant } = useTenant();
    return useQuery({
        queryKey: [...accountingRootKey(tenant?.id), 'reports', 'ledger', { accountId, from, to, page, perPage }],
        queryFn: async (): Promise<LedgerReport> => {
            const { data } = await apiClient.get('/accounting/reports/ledger', {
                params: {
                    account_id: accountId,
                    page,
                    per_page: perPage,
                    ...(from ? { from } : {}),
                    ...(to ? { to } : {}),
                },
            });
            return ledgerReportSchema.parse(data);
        },
        placeholderData: keepPreviousData,
        enabled: enabled && accountId !== null && !!tenant,
    });
}
