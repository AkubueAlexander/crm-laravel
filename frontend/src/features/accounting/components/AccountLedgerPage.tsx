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
