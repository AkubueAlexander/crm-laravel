import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// ---- account types ---------------------------------------------------------
export const ACCOUNT_TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'] as const;
export type AccountType = (typeof ACCOUNT_TYPES)[number];

export const ACCOUNT_TYPE_LABELS: Record<AccountType, string> = {
    asset: 'Asset',
    liability: 'Liability',
    equity: 'Equity',
    revenue: 'Revenue',
    expense: 'Expense',
};

// ---- API shapes (12.0b) ----------------------------------------------------
const moneyValue = z.union([z.string(), z.number()]).transform((v) => String(v));

export const ledgerAccountSchema = z.object({
    id: z.number(),
    code: z.string(),
    name: z.string(),
    type: z.enum(ACCOUNT_TYPES),
    is_active: z.boolean(),
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
});
export type LedgerAccount = z.infer<typeof ledgerAccountSchema>;

// The accounts list is a bare array (non-paginated); a {data: []} wrapper is tolerated too.
export const ledgerAccountListSchema = z.union([z.array(ledgerAccountSchema), z.object({ data: z.array(ledgerAccountSchema) })]);

export const journalLineSchema = z.object({
    id: z.number().optional(),
    account_id: z.number(),
    account_code: z.string().nullable().optional(),
    account_name: z.string().nullable().optional(),
    description: z.string().nullable().optional(),
    debit: moneyValue,
    credit: moneyValue,
});
export type JournalLine = z.infer<typeof journalLineSchema>;

export const journalEntrySchema = z.object({
    id: z.number(),
    entry_seq: z.number().optional(),
    entry_number: z.string(),
    entry_date: z.string(),
    memo: z.string().nullable().optional(),
    source_type: z.string().nullable().optional(),
    source_id: z.union([z.number(), z.string()]).nullable().optional(),
    reverses_entry_id: z.number().nullable().optional(),
    reversed_by_entry_id: z.number().nullable().optional(),
    posted_by: z.unknown().optional(),
    total: moneyValue.optional(),
    lines: z.array(journalLineSchema).optional(),
    created_at: z.string().nullable().optional(),
});
export type JournalEntry = z.infer<typeof journalEntrySchema>;

export const journalEntryPageSchema = z.object({
    data: z.array(journalEntrySchema),
    meta: paginationMetaSchema,
});
export type JournalEntryPage = z.infer<typeof journalEntryPageSchema>;

// ---- money helpers (cents arithmetic, display only: the server is the boundary) ----
export function toCents(value: string): number | null {
    const v = value.trim();
    if (v === '') return 0;
    const m = /^(\d{1,12})(?:\.(\d{1,2}))?$/.exec(v);
    if (!m) return null;
    return Number(m[1]) * 100 + Number((m[2] ?? '').padEnd(2, '0'));
}

export function formatCents(cents: number): string {
    return (cents / 100).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function formatLedgerAmount(value: string | undefined): string {
    const n = Number(value);
    if (value === undefined || Number.isNaN(n)) return value ?? '-';
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function amountOrDash(value: string | undefined): string {
    return Number(value) === 0 ? '-' : formatLedgerAmount(value);
}

export function dateOnly(value: string | null | undefined): string {
    return value ? value.slice(0, 10) : '-';
}

export function todayIso(): string {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

// ---- forms (Zod is UX; the Actions are the boundary) -------------------------
export const accountFormSchema = z.object({
    code: z
        .string()
        .trim()
        .min(1, 'Enter an account code')
        .max(20, 'Keep the code to 20 characters')
        .regex(/^[A-Za-z0-9._-]+$/, 'Use letters, numbers, dots, dashes or underscores'),
    name: z.string().trim().min(1, 'Enter an account name').max(120, 'Keep the name to 120 characters'),
    type: z.enum(ACCOUNT_TYPES),
    is_active: z.boolean(),
});
export type AccountFormValues = z.infer<typeof accountFormSchema>;

const journalLineFormSchema = z
    .object({
        account_id: z.string().min(1, 'Choose an account'),
        description: z.string().max(255, 'Keep the description to 255 characters'),
        debit: z.string(),
        credit: z.string(),
    })
    .superRefine((line, ctx) => {
        const d = toCents(line.debit);
        const c = toCents(line.credit);
        if (d === null) ctx.addIssue({ code: 'custom', path: ['debit'], message: 'Enter an amount like 100.00' });
        if (c === null) ctx.addIssue({ code: 'custom', path: ['credit'], message: 'Enter an amount like 100.00' });
        if (d === null || c === null) return;
        if (d > 0 && c > 0) ctx.addIssue({ code: 'custom', path: ['debit'], message: 'Use either debit or credit, not both' });
        else if (d === 0 && c === 0) ctx.addIssue({ code: 'custom', path: ['debit'], message: 'Enter a debit or a credit amount' });
    });

export const journalFormSchema = z
    .object({
        entry_date: z.string().regex(DATE_RE, 'Choose a date'),
        memo: z.string().trim().min(1, 'Enter a memo').max(255, 'Keep the memo to 255 characters'),
        lines: z.array(journalLineFormSchema).min(2, 'A journal entry needs at least two lines').max(100, 'At most 100 lines'),
    })
    .superRefine((v, ctx) => {
        let debit = 0;
        let credit = 0;
        for (const l of v.lines) {
            const d = toCents(l.debit);
            const c = toCents(l.credit);
            if (d === null || c === null) return;
            debit += d;
            credit += c;
        }
        if (debit !== credit) {
            ctx.addIssue({ code: 'custom', path: ['lines'], message: `Debits and credits must balance (out by ${formatCents(Math.abs(debit - credit))}).` });
        }
    });
export type JournalFormValues = z.infer<typeof journalFormSchema>;

export function emptyJournalLine(): JournalFormValues['lines'][number] {
    return { account_id: '', description: '', debit: '', credit: '' };
}

export const reverseFormSchema = z.object({
    reason: z.string().trim().min(1, 'Enter a reason').max(500, 'Keep the reason to 500 characters'),
    entry_date: z.string().refine((v) => v === '' || DATE_RE.test(v), 'Choose a valid date'),
});
export type ReverseFormValues = z.infer<typeof reverseFormSchema>;

// ---- URL search for the journal list (8.7); never throws ---------------------
export type JournalSearch = {
    q?: string;
    account_id?: number;
    from?: string;
    to?: string;
    page?: number;
};

export function validateJournalSearch(raw: Record<string, unknown>): JournalSearch {
    const out: JournalSearch = {};
    const q = typeof raw.q === 'string' ? raw.q.trim() : '';
    if (q) out.q = q.slice(0, 100);
    const account = Number(raw.account_id);
    if (Number.isInteger(account) && account > 0) out.account_id = account;
    if (typeof raw.from === 'string' && DATE_RE.test(raw.from)) out.from = raw.from;
    if (typeof raw.to === 'string' && DATE_RE.test(raw.to)) out.to = raw.to;
    const page = Number(raw.page);
    if (Number.isInteger(page) && page > 1) out.page = page;
    return out;
}
