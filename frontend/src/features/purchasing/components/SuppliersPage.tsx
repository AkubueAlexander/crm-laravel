import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useSuppliers } from '../api/usePurchasing';
import { validateSuppliersSearch, type Supplier, type SupplierSortColumn, type SuppliersSearch } from '../schemas/purchasing';
import { SupplierFormModal } from './SupplierFormModal';

const columnHelper = createColumnHelper<Supplier>();
const EMPTY: Supplier[] = [];

export function SuppliersPage() {
    const allowed = usePermission('inventory.view');
    const canManage = usePermission('inventory.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateSuppliersSearch(raw);

    const page = search.page ?? 1;
    const sort: SupplierSortColumn = search.sort ?? 'name';
    const direction = search.direction ?? 'asc';

    function setSearch(patch: Partial<SuppliersSearch>) {
        void navigate({ to: '/suppliers', search: validateSuppliersSearch({ ...search, ...patch }), replace: true });
    }

    const [editing, setEditing] = useState<Supplier | null>(null);
    const [formOpen, setFormOpen] = useState(false);
    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useSuppliers({ page, sort, direction, q: search.q, active: search.active, enabled: allowed });

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('name', { header: 'Name', cell: (info) => <span className="font-medium">{info.getValue()}</span> }),
            columnHelper.accessor('email', { header: 'Email', cell: (info) => info.getValue() ?? '-', enableSorting: false }),
            columnHelper.accessor('phone', { header: 'Phone', cell: (info) => info.getValue() ?? '-', enableSorting: false }),
            columnHelper.accessor('is_active', {
                header: 'Status',
                cell: (info) => <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">{info.getValue() ? 'Active' : 'Inactive'}</span>,
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) =>
                    canManage ? (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(row.original);
                                setFormOpen(true);
                            }}
                            className="rounded-md border px-2 py-1 text-xs"
                        >
                            Edit
                        </button>
                    ) : null,
                enableSorting: false,
            }),
        ],
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
            setSearch({ sort: first.id as SupplierSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to suppliers.</p>;

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.active);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">Suppliers</h1>
                    <Link to="/purchase-orders" className="text-sm underline">
                        Purchase orders
                    </Link>
                </div>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => {
                                setEditing(null);
                                setFormOpen(true);
                            }}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90"
                        >
                            New supplier
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input type="search" placeholder="Search name or email..." value={qInput} onChange={(e) => setQInput(e.target.value)} className="w-64 rounded-md border px-3 py-2 text-sm" />
                <select
                    aria-label="Filter by status"
                    value={search.active ?? ''}
                    onChange={(e) => setSearch({ active: (e.target.value || undefined) as SuppliersSearch['active'], page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All suppliers</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/suppliers', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load suppliers.")}
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
                                    {hasFilters ? 'No suppliers match these filters.' : 'No suppliers yet.'}
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

            <SupplierFormModal open={formOpen} onClose={() => setFormOpen(false)} supplier={editing} />
        </div>
    );
}
