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
