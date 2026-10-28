import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { useInvoices } from '../api/useInvoices';
import {
    INVOICE_STATUSES,
    INVOICE_STATUS_LABELS,
    validateInvoicesSearch,
    type Invoice,
    type InvoiceSortColumn,
    type InvoiceStatus,
    type InvoicesSearch,
} from '../schemas/invoice';

const columnHelper = createColumnHelper<Invoice>();
const EMPTY: Invoice[] = [];

export function InvoicesPage() {
    const allowed = usePermission('invoices.view');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateInvoicesSearch(raw);

    const page = search.page ?? 1;
    const sort: InvoiceSortColumn = search.sort ?? 'invoice_number';
    const direction = search.direction ?? 'desc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<InvoicesSearch>) {
        void navigate({ to: '/invoices', search: validateInvoicesSearch({ ...search, ...patch }), replace: true });
    }

    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useInvoices({ page, sort, direction, q: search.q, status: search.status, enabled: allowed });

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('invoice_number', {
                header: 'Number',
                cell: (info) => (
                    <Link to="/invoices/$invoiceId" params={{ invoiceId: String(info.row.original.id) }} className="font-mono text-xs underline">
                        {info.getValue()}
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
                        {INVOICE_STATUS_LABELS[info.getValue()]}
                    </span>
                ),
            }),
            columnHelper.accessor('total', {
                header: 'Total',
                cell: (info) => formatMoney(info.getValue(), info.row.original.currency),
            }),
            columnHelper.accessor('due_date', {
                header: 'Due',
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
                cell: ({ row }) => (
                    <Link to="/invoices/$invoiceId" params={{ invoiceId: String(row.original.id) }} className="rounded-md border px-2 py-1 text-xs">
                        Open
                    </Link>
                ),
                enableSorting: false,
            }),
        ],
        [],
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
            setSearch({ sort: first.id as InvoiceSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to invoices.</p>;
    }

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.status);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Invoices</h1>
                {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
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
                    onChange={(e) => setSearch({ status: (e.target.value || undefined) as InvoiceStatus | undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All statuses</option>
                    {INVOICE_STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {INVOICE_STATUS_LABELS[s]}
                        </option>
                    ))}
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/invoices', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load invoices.")}
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
                                    {hasFilters ? 'No invoices match these filters.' : 'No invoices yet. Create one from an accepted quote.'}
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
        </div>
    );
}
