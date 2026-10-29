import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useJournalEntries, useLedgerAccounts } from '../api/useAccounting';
import { dateOnly, formatLedgerAmount, validateJournalSearch, type JournalEntry, type JournalSearch } from '../schemas/accounting';

const columnHelper = createColumnHelper<JournalEntry>();
const EMPTY: JournalEntry[] = [];

// 12.1a: journal (entries list). Filters and page live in the URL (8.7).
export function JournalPage() {
    const allowed = usePermission('accounting.view');
    const canManage = usePermission('accounting.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateJournalSearch(raw);
    const page = search.page ?? 1;

    function setSearch(patch: Partial<JournalSearch>) {
        void navigate({ to: '/accounting', search: validateJournalSearch({ ...search, ...patch }), replace: true });
    }

    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const accounts = useLedgerAccounts(allowed);
    const { data, isPending, isFetching, isError, error } = useJournalEntries({
        page,
        q: search.q,
        accountId: search.account_id,
        from: search.from,
        to: search.to,
        enabled: allowed,
    });

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('entry_number', {
                header: 'Entry',
                cell: (info) => (
                    <Link to="/accounting/journal/$entryId" params={{ entryId: String(info.row.original.id) }} className="font-mono text-xs underline">
                        {info.getValue()}
                    </Link>
                ),
            }),
            columnHelper.accessor('entry_date', { header: 'Date', cell: (info) => dateOnly(info.getValue()) }),
            columnHelper.accessor('memo', { header: 'Memo', cell: (info) => <span className="font-medium">{info.getValue() ?? ''}</span> }),
            columnHelper.accessor('total', {
                header: 'Total',
                cell: (info) => <span className="block text-right">{formatLedgerAmount(info.getValue())}</span>,
            }),
            columnHelper.display({
                id: 'state',
                header: '',
                cell: ({ row }) => (
                    <span className="flex gap-1">
                        {row.original.reversed_by_entry_id ? <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">Reversed</span> : null}
                        {row.original.reverses_entry_id ? <span className="rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">Reversal</span> : null}
                    </span>
                ),
            }),
        ],
        [],
    );

    const table = useReactTable({
        data: data?.data ?? EMPTY,
        columns,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to accounting.</p>;

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.account_id || search.from || search.to);
    const accountOptions = [...(accounts.data ?? [])].sort((a, b) => a.code.localeCompare(b.code));

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">Journal</h1>
                    <Link to="/accounting/reports/trial-balance" className="text-sm underline">
                        Reports
                    </Link>
                    <Link to="/accounting/accounts" className="text-sm underline">
                        Chart of accounts
                    </Link>
                </div>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <Link to="/accounting/journal/new" className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                            New entry
                        </Link>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input type="search" placeholder="Search memo or entry number..." value={qInput} onChange={(e) => setQInput(e.target.value)} className="w-64 rounded-md border px-3 py-2 text-sm" />
                <select
                    aria-label="Filter by account"
                    value={search.account_id ? String(search.account_id) : ''}
                    onChange={(e) => setSearch({ account_id: e.target.value ? Number(e.target.value) : undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All accounts</option>
                    {accountOptions.map((a) => (
                        <option key={a.id} value={a.id}>
                            {a.code} - {a.name}
                        </option>
                    ))}
                </select>
                <label className="flex items-center gap-1 text-sm">
                    From
                    <input type="date" value={search.from ?? ''} onChange={(e) => setSearch({ from: e.target.value || undefined, page: undefined })} className="rounded-md border px-2 py-2 text-sm" />
                </label>
                <label className="flex items-center gap-1 text-sm">
                    To
                    <input type="date" value={search.to ?? ''} onChange={(e) => setSearch({ to: e.target.value || undefined, page: undefined })} className="rounded-md border px-2 py-2 text-sm" />
                </label>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/accounting', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load the journal.")}
                </p>
            )}

            {isPending ? (
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
                            <tr key={row.original.id}>
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
                                    {hasFilters ? 'No entries match these filters.' : 'No journal entries yet.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            )}

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
        </div>
    );
}
