import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useQuotes } from '../api/useQuotes';
import { useDeleteQuote } from '../api/useQuoteMutations';
import {
    QUOTE_STATUSES,
    QUOTE_STATUS_LABELS,
    formatMoney,
    validateQuotesSearch,
    type Quote,
    type QuoteSortColumn,
    type QuoteStatus,
    type QuotesSearch,
} from '../schemas/quote';

const columnHelper = createColumnHelper<Quote>();
const EMPTY: Quote[] = [];

export function QuotesPage() {
    const allowed = usePermission('quotes.view');
    const canManage = usePermission('quotes.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateQuotesSearch(raw);

    const page = search.page ?? 1;
    const sort: QuoteSortColumn = search.sort ?? 'quote_number';
    const direction = search.direction ?? 'desc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<QuotesSearch>) {
        void navigate({ to: '/quotes', search: validateQuotesSearch({ ...search, ...patch }), replace: true });
    }

    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useQuotes({ page, sort, direction, q: search.q, status: search.status, enabled: allowed });

    // After deleting the last row of the last page, step back instead of showing an empty page.
    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const del = useDeleteQuote();
    const [pendingDelete, setPendingDelete] = useState<Quote | null>(null);

    const columns = useMemo(
        () => [
            columnHelper.accessor('quote_number', {
                header: 'Number',
                cell: (info) => (
                    <Link to="/quotes/$quoteId" params={{ quoteId: String(info.row.original.id) }} className="font-mono text-xs underline">
                        {info.getValue()}
                        {info.row.original.version > 1 ? ` v${info.row.original.version}` : ''}
                    </Link>
                ),
            }),
            columnHelper.accessor('title', {
                header: 'Title',
                cell: (info) => <span className="font-medium">{info.getValue()}</span>,
            }),
            columnHelper.accessor('status', {
                header: 'Status',
                cell: (info) => (
                    <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                        {QUOTE_STATUS_LABELS[info.getValue()]}
                    </span>
                ),
            }),
            columnHelper.accessor('total', {
                header: 'Total',
                cell: (info) => formatMoney(info.getValue(), info.row.original.currency),
            }),
            columnHelper.accessor('valid_until', {
                header: 'Valid until',
                cell: (info) => info.getValue() ?? '-',
            }),
            columnHelper.accessor('lines_count', {
                header: 'Lines',
                cell: (info) => info.getValue() ?? 0,
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => {
                    const quote = row.original;
                    return (
                        <div className="flex gap-2">
                            <Link to="/quotes/$quoteId" params={{ quoteId: String(quote.id) }} className="rounded-md border px-2 py-1 text-xs">
                                {canManage && quote.status === 'draft' ? 'Edit' : 'Open'}
                            </Link>
                            {canManage && quote.status === 'draft' && (
                                <button
                                    type="button"
                                    onClick={() => {
                                        del.reset();
                                        setPendingDelete(quote);
                                    }}
                                    className="rounded-md border px-2 py-1 text-xs text-red-700"
                                >
                                    Delete
                                </button>
                            )}
                        </div>
                    );
                },
                enableSorting: false,
            }),
        ],
        // del.reset is stable; the setters are state setters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [canManage],
    );

    const sorting: SortingState = [{ id: sort, desc: direction === 'desc' }];

    const table = useReactTable({
        data: data?.data ?? EMPTY,
        columns,
        state: { sorting },
        onSortingChange: (updater) => {
            const next = typeof updater === 'function' ? updater(sorting) : updater;
            const first = next[0];
            if (!first) return;
            setSearch({ sort: first.id as QuoteSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to quotes.</p>;
    }

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.status);

    function confirmDelete() {
        if (!pendingDelete) return;
        del.mutate(pendingDelete.id, { onSuccess: () => setPendingDelete(null) });
    }

    function closeDelete() {
        del.reset();
        setPendingDelete(null);
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Quotes</h1>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <Link to="/quotes/new" className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                            New quote
                        </Link>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    placeholder="Search number or title..."
                    value={qInput}
                    onChange={(e) => setQInput(e.target.value)}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                <select
                    aria-label="Filter by status"
                    value={search.status ?? ''}
                    onChange={(e) => setSearch({ status: (e.target.value || undefined) as QuoteStatus | undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All statuses</option>
                    {QUOTE_STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {QUOTE_STATUS_LABELS[s]}
                        </option>
                    ))}
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/quotes', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load quotes.")}
                </p>
            )}

            {isPending ? (
                <p className="text-sm text-gray-500">Loading...</p>
            ) : (
                <table className="w-full border-collapse text-sm">
                    <thead>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <tr key={headerGroup.id}>
                                {headerGroup.headers.map((header) => {
                                    const canSort = header.column.getCanSort();
                                    const sortDir = header.column.getIsSorted();
                                    return (
                                        <th
                                            key={header.id}
                                            onClick={canSort ? header.column.getToggleSortingHandler() : undefined}
                                            className={`border-b px-3 py-2 text-left font-medium ${canSort ? 'cursor-pointer select-none' : ''}`}
                                        >
                                            {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                            {sortDir === 'asc' ? ' \u25b2' : sortDir === 'desc' ? ' \u25bc' : ''}
                                        </th>
                                    );
                                })}
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
                                    {hasFilters ? 'No quotes match these filters.' : 'No quotes yet.'}
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
                        <button
                            type="button"
                            disabled={page >= meta.last_page}
                            onClick={() => setSearch({ page: page + 1 })}
                            className="rounded-md border px-2 py-1 disabled:opacity-40"
                        >
                            Next
                        </button>
                    </div>
                </div>
            )}

            <Modal open={pendingDelete !== null} onClose={closeDelete} title="Delete quote?">
                <p className="text-sm text-[var(--color-ink-muted)]">
                    {pendingDelete?.quote_number} &ldquo;{pendingDelete?.title}&rdquo; and its lines will be removed. This cannot be undone.
                </p>
                {del.isError && (
                    <p role="alert" className="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {apiErrorMessage(del.error, 'Could not delete the quote. Please try again.')}
                    </p>
                )}
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={closeDelete} disabled={del.isPending} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={confirmDelete}
                        disabled={del.isPending}
                        className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {del.isPending ? 'Deleting...' : 'Delete'}
                    </button>
                </div>
            </Modal>
        </div>
    );
}
