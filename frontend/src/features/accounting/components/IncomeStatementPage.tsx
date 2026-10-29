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
