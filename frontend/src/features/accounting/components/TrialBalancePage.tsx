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
