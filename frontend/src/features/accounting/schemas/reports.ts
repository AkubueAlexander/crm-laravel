import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

const money = z.union([z.string(), z.number()]).transform((v) => String(v));
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

// ---- API shapes (12.0b reports, all read-only) ----------------------------------
export const trialBalanceRowSchema = z.object({
    account_id: z.number(),
    code: z.string(),
    name: z.string(),
    type: z.string().optional(),
    debit: money,
    credit: money,
});
export type TrialBalanceRow = z.infer<typeof trialBalanceRowSchema>;

export const trialBalanceSchema = z.object({
    as_of: z.string().nullable().optional(),
    rows: z.array(trialBalanceRowSchema),
    totals: z.object({ debit: money, credit: money, balanced: z.boolean() }),
});
export type TrialBalance = z.infer<typeof trialBalanceSchema>;

export const statementRowSchema = z.object({
    account_id: z.number().optional(),
    code: z.string(),
    name: z.string(),
    type: z.string().optional(),
    amount: money,
});
export type StatementRow = z.infer<typeof statementRowSchema>;

export const incomeStatementSchema = z.object({
    from: z.string().nullable().optional(),
    to: z.string().nullable().optional(),
    revenue: z.array(statementRowSchema),
    expenses: z.array(statementRowSchema),
    total_revenue: money,
    total_expenses: money,
    net_income: money,
});
export type IncomeStatement = z.infer<typeof incomeStatementSchema>;

export const balanceSheetSchema = z.object({
    as_of: z.string().nullable().optional(),
    assets: z.array(statementRowSchema),
    liabilities: z.array(statementRowSchema),
    equity: z.array(statementRowSchema),
    current_earnings: money,
    total_assets: money,
    total_liabilities: money,
    total_equity: money,
    total_liabilities_and_equity: money,
    balanced: z.boolean(),
});
export type BalanceSheet = z.infer<typeof balanceSheetSchema>;

export const ledgerRowSchema = z.object({
    entry_id: z.number(),
    entry_number: z.string(),
    entry_date: z.string(),
    memo: z.string().nullable().optional(),
    description: z.string().nullable().optional(),
    debit: money,
    credit: money,
    balance: money,
});
export type LedgerRow = z.infer<typeof ledgerRowSchema>;

export const ledgerReportSchema = z.object({
    data: z.array(ledgerRowSchema),
    meta: paginationMetaSchema,
    account: z.object({ id: z.number(), code: z.string(), name: z.string(), type: z.string() }),
    opening_balance: money,
});
export type LedgerReport = z.infer<typeof ledgerReportSchema>;

// ---- URL search (8.7); validators never throw --------------------------------------
export type AsOfSearch = { as_of?: string };
export type RangeSearch = { from?: string; to?: string };
export type LedgerSearch = { account_id?: number; from?: string; to?: string; page?: number };

export function validateAsOfSearch(raw: Record<string, unknown>): AsOfSearch {
    const out: AsOfSearch = {};
    if (typeof raw.as_of === 'string' && DATE_RE.test(raw.as_of)) out.as_of = raw.as_of;
    return out;
}

export function validateRangeSearch(raw: Record<string, unknown>): RangeSearch {
    const out: RangeSearch = {};
    if (typeof raw.from === 'string' && DATE_RE.test(raw.from)) out.from = raw.from;
    if (typeof raw.to === 'string' && DATE_RE.test(raw.to)) out.to = raw.to;
    return out;
}

export function validateLedgerSearch(raw: Record<string, unknown>): LedgerSearch {
    const out: LedgerSearch = {};
    const account = Number(raw.account_id);
    if (Number.isInteger(account) && account > 0) out.account_id = account;
    if (typeof raw.from === 'string' && DATE_RE.test(raw.from)) out.from = raw.from;
    if (typeof raw.to === 'string' && DATE_RE.test(raw.to)) out.to = raw.to;
    const page = Number(raw.page);
    if (Number.isInteger(page) && page > 1) out.page = page;
    return out;
}

export function startOfYearIso(): string {
    return `${new Date().getFullYear()}-01-01`;
}
