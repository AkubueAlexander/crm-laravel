$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

$utf8 = New-Object System.Text.UTF8Encoding($false)
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $report.Add($m) }

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = [System.IO.Path]::GetDirectoryName($Path)
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $text = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $text, $utf8)
}

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $orig = [System.IO.File]::ReadAllText($Path, $utf8)
    $crlf = $orig.Contains("`r`n")
    $t = $orig.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor not exactly once in ${Path}: $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, $utf8)
    return 'patched'
}

$routerPath = Join-Path $repo 'frontend\src\router.tsx'
$journalPagePath = Join-Path $repo 'frontend\src\features\accounting\components\JournalPage.tsx'
foreach ($p in @($routerPath, $journalPagePath, (Join-Path $repo 'frontend\src\features\accounting\schemas\accounting.ts'), (Join-Path $repo 'frontend\src\features\accounting\api\useAccounting.ts'))) {
    if (-not (Test-Path $p)) { throw "Prerequisite missing (run 12.1a first): $p" }
}

$files = New-Object System.Collections.ArrayList

$schemas = @'
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
'@
[void]$files.Add(@{ Path = 'frontend\src\features\accounting\schemas\reports.ts'; Content = $schemas })

$queries = @'
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
'@
[void]$files.Add(@{ Path = 'frontend\src\features\accounting\api\useReports.ts'; Content = $queries })

$shell = @'
import type { ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { formatLedgerAmount } from '../schemas/accounting';
import type { StatementRow } from '../schemas/reports';

const TAB_CLASS = 'rounded-md px-2 py-1 text-sm text-[var(--color-ink-muted)] hover:bg-[var(--color-paper)] [&.active]:bg-[var(--color-paper)] [&.active]:font-medium [&.active]:text-[var(--color-ink)]';

// 12.1b: shared chrome for the report pages: tabs plus the mandatory "not your books of record" banner (12.1).
export function ReportShell({ title, children }: { title: string; children: ReactNode }) {
    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/accounting" className="text-[var(--color-ink-muted)] underline">
                    Journal
                </Link>
            </div>
            <h1 className="mb-3 text-lg font-medium text-[var(--color-ink)]">{title}</h1>
            <nav className="mb-3 flex flex-wrap gap-1" aria-label="Reports">
                <Link to="/accounting/reports/trial-balance" className={TAB_CLASS}>
                    Trial balance
                </Link>
                <Link to="/accounting/reports/income-statement" className={TAB_CLASS}>
                    Income statement
                </Link>
                <Link to="/accounting/reports/balance-sheet" className={TAB_CLASS}>
                    Balance sheet
                </Link>
                <Link to="/accounting/reports/ledger" className={TAB_CLASS}>
                    Account ledger
                </Link>
            </nav>
            <p role="note" className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Operational reporting, not your books of record (QuickBooks/Xero are).
            </p>
            {children}
        </div>
    );
}

export function DateFilter({ label, value, onChange }: { label: string; value: string; onChange: (v: string) => void }) {
    return (
        <label className="flex items-center gap-1 text-sm">
            {label}
            <input type="date" value={value} onChange={(e) => onChange(e.target.value)} className="rounded-md border px-2 py-2 text-sm" />
        </label>
    );
}

export function Badge({ ok, okText, badText }: { ok: boolean; okText: string; badText: string }) {
    return (
        <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${ok ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'}`}>{ok ? okText : badText}</span>
    );
}

export function StatementSection({
    title,
    rows,
    totalLabel,
    total,
    extra,
}: {
    title: string;
    rows: StatementRow[];
    totalLabel: string;
    total: string;
    extra?: { label: string; amount: string };
}) {
    return (
        <section className="mb-6">
            <h2 className="mb-2 text-base font-medium text-[var(--color-ink)]">{title}</h2>
            <table className="w-full border-collapse text-sm">
                <tbody>
                    {rows.map((r) => (
                        <tr key={`${r.code}-${r.name}`}>
                            <td className="w-24 border-b px-3 py-2 font-mono text-xs">
                                {r.account_id ? (
                                    <Link to="/accounting/reports/ledger" search={{ account_id: r.account_id }} className="underline">
                                        {r.code}
                                    </Link>
                                ) : (
                                    r.code
                                )}
                            </td>
                            <td className="border-b px-3 py-2">{r.name}</td>
                            <td className="border-b px-3 py-2 text-right">{formatLedgerAmount(r.amount)}</td>
                        </tr>
                    ))}
                    {extra && (
                        <tr>
                            <td className="border-b px-3 py-2" />
                            <td className="border-b px-3 py-2 italic">{extra.label}</td>
                            <td className="border-b px-3 py-2 text-right">{formatLedgerAmount(extra.amount)}</td>
                        </tr>
                    )}
                    {rows.length === 0 && !extra && (
                        <tr>
                            <td colSpan={3} className="px-3 py-4 text-center text-gray-500">
                                No accounts with a balance.
                            </td>
                        </tr>
                    )}
                </tbody>
                <tfoot>
                    <tr>
                        <td colSpan={2} className="px-3 py-2 font-medium">
                            {totalLabel}
                        </td>
                        <td className="px-3 py-2 text-right font-medium">{formatLedgerAmount(total)}</td>
                    </tr>
                </tfoot>
            </table>
        </section>
    );
}
'@
[void]$files.Add(@{ Path = 'frontend\src\features\accounting\components\ReportShell.tsx'; Content = $shell })

$trial = @'
import { useMemo } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTrialBalance } from '../api/useReports';
import { ACCOUNT_TYPE_LABELS, amountOrDash, formatLedgerAmount, todayIso, type AccountType } from '../schemas/accounting';
import { validateAsOfSearch, type TrialBalanceRow } from '../schemas/reports';
import { Badge, DateFilter, ReportShell } from './ReportShell';

const columnHelper = createColumnHelper<TrialBalanceRow>();
const EMPTY: TrialBalanceRow[] = [];

// 12.1b: trial balance as of a date (URL-persisted).
export function TrialBalancePage() {
    const allowed = usePermission('accounting.view');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateAsOfSearch(raw);
    const asOf = search.as_of ?? todayIso();

    const query = useTrialBalance({ asOf, enabled: allowed });
    const rows = query.data?.rows ?? EMPTY;

    const columns = useMemo(
        () => [
            columnHelper.accessor('code', {
                header: 'Code',
                cell: (info) => (
                    <Link to="/accounting/reports/ledger" search={{ account_id: info.row.original.account_id }} className="font-mono text-xs underline">
                        {info.getValue()}
                    </Link>
                ),
            }),
            columnHelper.accessor('name', { header: 'Account', cell: (info) => <span className="font-medium">{info.getValue()}</span> }),
            columnHelper.accessor('type', {
                header: 'Type',
                cell: (info) => ACCOUNT_TYPE_LABELS[info.getValue() as AccountType] ?? info.getValue() ?? '',
            }),
            columnHelper.accessor('debit', { header: 'Debit', cell: (info) => <span className="block text-right">{amountOrDash(info.getValue())}</span> }),
            columnHelper.accessor('credit', { header: 'Credit', cell: (info) => <span className="block text-right">{amountOrDash(info.getValue())}</span> }),
        ],
        [],
    );

    const table = useReactTable({ data: rows, columns, getCoreRowModel: getCoreRowModel() });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to accounting.</p>;

    const totals = query.data?.totals;

    return (
        <ReportShell title="Trial balance">
            <div className="mb-3 flex flex-wrap items-center gap-3">
                <DateFilter label="As of" value={asOf} onChange={(v) => void navigate({ to: '/accounting/reports/trial-balance', search: validateAsOfSearch({ as_of: v }), replace: true })} />
                {query.isFetching && !query.isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                {totals && <Badge ok={totals.balanced} okText="Balanced" badText="Out of balance" />}
            </div>

            {query.isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(query.error, "Couldn't load the trial balance.")}
                </p>
            )}

            {query.isPending ? (
                <p className="text-sm text-gray-500">Loading...</p>
            ) : (
                <table className="w-full border-collapse text-sm">
                    <thead>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <tr key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <th key={header.id} className="border-b px-3 py-2 text-left font-medium">
                                        {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                    </th>
                                ))}
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.map((row) => (
                            <tr key={row.id}>
                                {row.getVisibleCells().map((cell) => (
                                    <td key={cell.id} className="border-b px-3 py-2 align-top">
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="px-3 py-6 text-center text-gray-500">
                                    No postings as of this date.
                                </td>
                            </tr>
                        )}
                    </tbody>
                    {totals && (
                        <tfoot>
                            <tr>
                                <td colSpan={3} className="px-3 py-2 font-medium">
                                    Totals
                                </td>
                                <td className="px-3 py-2 text-right font-medium">{formatLedgerAmount(totals.debit)}</td>
                                <td className="px-3 py-2 text-right font-medium">{formatLedgerAmount(totals.credit)}</td>
                            </tr>
                        </tfoot>
                    )}
                </table>
            )}
        </ReportShell>
    );
}
'@
[void]$files.Add(@{ Path = 'frontend\src\features\accounting\components\TrialBalancePage.tsx'; Content = $trial })

$income = @'
import { useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useIncomeStatement } from '../api/useReports';
import { formatLedgerAmount, todayIso } from '../schemas/accounting';
import { startOfYearIso, validateRangeSearch } from '../schemas/reports';
import { DateFilter, ReportShell, StatementSection } from './ReportShell';

// 12.1b: income statement for a period (URL-persisted; defaults to year to date).
export function IncomeStatementPage() {
    const allowed = usePermission('accounting.view');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateRangeSearch(raw);
    const from = search.from ?? startOfYearIso();
    const to = search.to ?? todayIso();

    const query = useIncomeStatement({ from, to, enabled: allowed });

    function setRange(patch: { from?: string; to?: string }) {
        void navigate({ to: '/accounting/reports/income-statement', search: validateRangeSearch({ from, to, ...patch }), replace: true });
    }

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to accounting.</p>;

    const data = query.data;
    const net = Number(data?.net_income ?? 0);

    return (
        <ReportShell title="Income statement">
            <div className="mb-3 flex flex-wrap items-center gap-3">
                <DateFilter label="From" value={from} onChange={(v) => setRange({ from: v })} />
                <DateFilter label="To" value={to} onChange={(v) => setRange({ to: v })} />
                {query.isFetching && !query.isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
            </div>

            {query.isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(query.error, "Couldn't load the income statement.")}
                </p>
            )}

            {query.isPending ? (
                <p className="text-sm text-gray-500">Loading...</p>
            ) : data ? (
                <div className="max-w-3xl">
                    <StatementSection title="Revenue" rows={data.revenue} totalLabel="Total revenue" total={data.total_revenue} />
                    <StatementSection title="Expenses" rows={data.expenses} totalLabel="Total expenses" total={data.total_expenses} />
                    <div className="flex items-center justify-between rounded-md border px-3 py-3 text-sm font-medium">
                        <span>Net income</span>
                        <span className={net < 0 ? 'text-red-700' : 'text-green-700'}>{formatLedgerAmount(data.net_income)}</span>
                    </div>
                </div>
            ) : null}
        </ReportShell>
    );
}
'@
[void]$files.Add(@{ Path = 'frontend\src\features\accounting\components\IncomeStatementPage.tsx'; Content = $income })

$balance = @'
import { useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useBalanceSheet } from '../api/useReports';
import { formatLedgerAmount, todayIso } from '../schemas/accounting';
import { validateAsOfSearch } from '../schemas/reports';
import { Badge, DateFilter, ReportShell, StatementSection } from './ReportShell';

// 12.1b: balance sheet as of a date (URL-persisted). Current earnings are shown inside equity.
export function BalanceSheetPage() {
    const allowed = usePermission('accounting.view');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateAsOfSearch(raw);
    const asOf = search.as_of ?? todayIso();

    const query = useBalanceSheet({ asOf, enabled: allowed });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to accounting.</p>;

    const data = query.data;

    return (
        <ReportShell title="Balance sheet">
            <div className="mb-3 flex flex-wrap items-center gap-3">
                <DateFilter label="As of" value={asOf} onChange={(v) => void navigate({ to: '/accounting/reports/balance-sheet', search: validateAsOfSearch({ as_of: v }), replace: true })} />
                {query.isFetching && !query.isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                {data && <Badge ok={data.balanced} okText="Balanced" badText="Out of balance" />}
            </div>

            {query.isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(query.error, "Couldn't load the balance sheet.")}
                </p>
            )}

            {query.isPending ? (
                <p className="text-sm text-gray-500">Loading...</p>
            ) : data ? (
                <div className="max-w-3xl">
                    <StatementSection title="Assets" rows={data.assets} totalLabel="Total assets" total={data.total_assets} />
                    <StatementSection title="Liabilities" rows={data.liabilities} totalLabel="Total liabilities" total={data.total_liabilities} />
                    <StatementSection
                        title="Equity"
                        rows={data.equity}
                        extra={{ label: 'Current earnings', amount: data.current_earnings }}
                        totalLabel="Total equity"
                        total={data.total_equity}
                    />
                    <div className="flex items-center justify-between rounded-md border px-3 py-3 text-sm font-medium">
                        <span>Total liabilities and equity</span>
                        <span>{formatLedgerAmount(data.total_liabilities_and_equity)}</span>
                    </div>
                </div>
            ) : null}
        </ReportShell>
    );
}
'@
[void]$files.Add(@{ Path = 'frontend\src\features\accounting\components\BalanceSheetPage.tsx'; Content = $balance })

$ledger = @'
import { useEffect, useMemo } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useLedgerAccounts } from '../api/useAccounting';
import { useAccountLedger } from '../api/useReports';
import { ACCOUNT_TYPE_LABELS, amountOrDash, dateOnly, formatLedgerAmount, type AccountType } from '../schemas/accounting';
import { validateLedgerSearch, type LedgerRow, type LedgerSearch } from '../schemas/reports';
import { DateFilter, ReportShell } from './ReportShell';

const columnHelper = createColumnHelper<LedgerRow>();
const EMPTY: LedgerRow[] = [];

// 12.1b: account ledger with a running balance. Account, dates and page live in the URL (8.7).
export function AccountLedgerPage() {
    const allowed = usePermission('accounting.view');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateLedgerSearch(raw);
    const page = search.page ?? 1;

    function setSearch(patch: Partial<LedgerSearch>) {
        void navigate({ to: '/accounting/reports/ledger', search: validateLedgerSearch({ ...search, ...patch }), replace: true });
    }

    const accounts = useLedgerAccounts(allowed);
    const query = useAccountLedger({ accountId: search.account_id ?? null, from: search.from, to: search.to, page, enabled: allowed });

    useEffect(() => {
        if (query.data && query.data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, query.data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [query.data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('entry_date', { header: 'Date', cell: (info) => dateOnly(info.getValue()) }),
            columnHelper.accessor('entry_number', {
                header: 'Entry',
                cell: (info) => (
                    <Link to="/accounting/journal/$entryId" params={{ entryId: String(info.row.original.entry_id) }} className="font-mono text-xs underline">
                        {info.getValue()}
                    </Link>
                ),
            }),
            columnHelper.display({
                id: 'description',
                header: 'Description',
                cell: ({ row }) => row.original.description || row.original.memo || '',
            }),
            columnHelper.accessor('debit', { header: 'Debit', cell: (info) => <span className="block text-right">{amountOrDash(info.getValue())}</span> }),
            columnHelper.accessor('credit', { header: 'Credit', cell: (info) => <span className="block text-right">{amountOrDash(info.getValue())}</span> }),
            columnHelper.accessor('balance', { header: 'Balance', cell: (info) => <span className="block text-right font-medium">{formatLedgerAmount(info.getValue())}</span> }),
        ],
        [],
    );

    const table = useReactTable({
        data: query.data?.data ?? EMPTY,
        columns,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to accounting.</p>;

    const meta = query.data?.meta;
    const account = query.data?.account;
    const accountOptions = [...(accounts.data ?? [])].sort((a, b) => a.code.localeCompare(b.code));

    return (
        <ReportShell title="Account ledger">
            <div className="mb-3 flex flex-wrap items-center gap-3">
                <select
                    aria-label="Account"
                    value={search.account_id ? String(search.account_id) : ''}
                    onChange={(e) => setSearch({ account_id: e.target.value ? Number(e.target.value) : undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">Choose an account...</option>
                    {accountOptions.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.code} - {a.name}
                        </option>
                    ))}
                </select>
                <DateFilter label="From" value={search.from ?? ''} onChange={(v) => setSearch({ from: v || undefined, page: undefined })} />
                <DateFilter label="To" value={search.to ?? ''} onChange={(v) => setSearch({ to: v || undefined, page: undefined })} />
                {query.isFetching && !query.isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
            </div>

            {!search.account_id ? (
                <p className="rounded-md border border-dashed p-6 text-center text-sm text-[var(--color-ink-muted)]">Choose an account to see its ledger.</p>
            ) : (
                <>
                    {query.isError && (
                        <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {apiErrorMessage(query.error, "Couldn't load the ledger.")}
                        </p>
                    )}
                    {query.isPending ? (
                        <p className="text-sm text-gray-500">Loading...</p>
                    ) : query.data ? (
                        <>
                            {account && (
                                <p className="mb-2 text-sm">
                                    <span className="font-mono text-xs">{account.code}</span> <span className="font-medium">{account.name}</span>{' '}
                                    <span className="text-[var(--color-ink-muted)]">({ACCOUNT_TYPE_LABELS[account.type as AccountType] ?? account.type})</span>
                                    {' - '}Opening balance: <span className="font-medium">{formatLedgerAmount(query.data.opening_balance)}</span>
                                </p>
                            )}
                            <table className="w-full border-collapse text-sm">
                                <thead>
                                    {table.getHeaderGroups().map((headerGroup) => (
                                        <tr key={headerGroup.id}>
                                            {headerGroup.headers.map((header) => (
                                                <th key={header.id} className="border-b px-3 py-2 text-left font-medium">
                                                    {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                                </th>
                                            ))}
                                        </tr>
                                    ))}
                                </thead>
                                <tbody>
                                    {table.getRowModel().rows.map((row) => (
                                        <tr key={row.id}>
                                            {row.getVisibleCells().map((cell) => (
                                                <td key={cell.id} className="border-b px-3 py-2 align-top">
                                                    {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                                </td>
                                            ))}
                                        </tr>
                                    ))}
                                    {table.getRowModel().rows.length === 0 && (
                                        <tr>
                                            <td colSpan={columns.length} className="px-3 py-6 text-center text-gray-500">
                                                No postings for this account in this period.
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                            {meta && (
                                <div className="mt-3 flex items-center justify-between text-sm text-gray-600">
                                    <span>
                                        {meta.from ?? 0}-{meta.to ?? 0} of {meta.total}
                                    </span>
                                    <div className="flex gap-2">
                                        <button type="button" disabled={page <= 1} onClick={() => setSearch({ page: page - 1 })} className="rounded-md border px-2 py-1 disabled:opacity-40">
                                            Previous
                                        </button>
                                        <button type="button" disabled={page >= meta.last_page} onClick={() => setSearch({ page: page + 1 })} className="rounded-md border px-2 py-1 disabled:opacity-40">
                                            Next
                                        </button>
                                    </div>
                                </div>
                            )}
                        </>
                    ) : null}
                </>
            )}
        </ReportShell>
    );
}
'@
[void]$files.Add(@{ Path = 'frontend\src\features\accounting\components\AccountLedgerPage.tsx'; Content = $ledger })

# ---------------------------------------------------------------- patches
$patches = New-Object System.Collections.ArrayList

$importsRepl = @'
import { validateJournalSearch } from '@/features/accounting/schemas/accounting';
import { TrialBalancePage } from '@/features/accounting/components/TrialBalancePage';
import { IncomeStatementPage } from '@/features/accounting/components/IncomeStatementPage';
import { BalanceSheetPage } from '@/features/accounting/components/BalanceSheetPage';
import { AccountLedgerPage } from '@/features/accounting/components/AccountLedgerPage';
import { validateAsOfSearch, validateLedgerSearch, validateRangeSearch } from '@/features/accounting/schemas/reports';
'@
[void]$patches.Add(@{
    Path = $routerPath
    Anchor = "import { validateJournalSearch } from '@/features/accounting/schemas/accounting';"
    Replacement = $importsRepl.TrimEnd("`r", "`n")
    Skip = 'features/accounting/components/TrialBalancePage'
})

$routesRepl = @'
// 12.1b: accounting reports. Dates/account/page live in the URL (8.7).
const trialBalanceRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/reports/trial-balance',
    validateSearch: validateAsOfSearch,
    component: TrialBalancePage,
});

const incomeStatementRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/reports/income-statement',
    validateSearch: validateRangeSearch,
    component: IncomeStatementPage,
});

const balanceSheetRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/reports/balance-sheet',
    validateSearch: validateAsOfSearch,
    component: BalanceSheetPage,
});

const accountLedgerRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/reports/ledger',
    validateSearch: validateLedgerSearch,
    component: AccountLedgerPage,
});

const routeTree = rootRoute.addChildren([
'@
[void]$patches.Add(@{
    Path = $routerPath
    Anchor = 'const routeTree = rootRoute.addChildren(['
    Replacement = $routesRepl.TrimEnd("`r", "`n")
    Skip = 'const trialBalanceRoute = createRoute'
})

[void]$patches.Add(@{
    Path = $routerPath
    Anchor = 'journalDetailRoute]),'
    Replacement = 'journalDetailRoute, trialBalanceRoute, incomeStatementRoute, balanceSheetRoute, accountLedgerRoute]),'
    Skip = 'accountLedgerRoute]),'
})

$linkRepl = @'
<Link to="/accounting/reports/trial-balance" className="text-sm underline">
                        Reports
                    </Link>
                    <Link to="/accounting/accounts" className="text-sm underline">
'@
[void]$patches.Add(@{
    Path = $journalPagePath
    Anchor = '<Link to="/accounting/accounts" className="text-sm underline">'
    Replacement = $linkRepl.TrimEnd("`r", "`n")
    Skip = '/accounting/reports/trial-balance'
})

# PREFLIGHT: every anchor exactly once in the ORIGINAL file (or Skip present), before anything is written
foreach ($p in $patches) {
    $orig = [System.IO.File]::ReadAllText($p.Path, $utf8).Replace("`r`n", "`n")
    if ($orig.Contains($p.Skip)) { continue }
    $n = Count-Sub $orig $p.Anchor
    if ($n -ne 1) { throw "PREFLIGHT: anchor occurs $n times (need 1) in $($p.Path): $($p.Anchor)" }
}

foreach ($f in $files) { Write-NoBom $f.Path $f.Content; Say "wrote $($f.Path)" }
foreach ($p in $patches) {
    $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip
    Say "$([System.IO.Path]::GetFileName($p.Path)): $res ($($p.Skip))"
}

Say 'Running tsc --noEmit...'
$ErrorActionPreference = 'Continue'
Push-Location (Join-Path $repo 'frontend')
$tsc = & npx.cmd tsc --noEmit 2>&1 | Out-String
$tscExit = $LASTEXITCODE
Pop-Location
$ErrorActionPreference = 'Stop'
if ($tscExit -eq 0) { Say 'tsc: clean' } else { Say "tsc: FAILED (exit $tscExit)"; Say $tsc }

Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): AppShell.tsx, backend accounting code (report payload shapes taken from the handover), apiClient/useTenant/usePermission internals.'
