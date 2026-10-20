import { useMemo, useState } from 'react';
import { useReactTable, getCoreRowModel, createColumnHelper, flexRender, type SortingState } from '@tanstack/react-table';
import { useLeads } from '../api/useLeads';
import { useDeleteLead } from '../api/useLeadMutations';
import { LEAD_FILTERABLE_STATUSES, LEAD_SORTABLE_COLUMNS, type Lead, type LeadSortColumn } from '../schemas/lead';

type LeadListProps = { onEdit: (lead: Lead) => void };

const columnHelper = createColumnHelper<Lead>();

// 5.2/8.4 pattern: manualPagination/manualSorting - the API does the
// LIMIT/ORDER BY, the table renders whatever page TanStack Query handed it.
// Filters/sort are local state for now; URL-persistence (8.7) is a follow-up
// once the router setup file is shown (same gap ContactList presumably has).
export function LeadList({ onEdit }: LeadListProps) {
    const [page, setPage] = useState(1);
    const [sorting, setSorting] = useState<SortingState>([{ id: 'created_at', desc: true }]);
    const [statusFilter, setStatusFilter] = useState('');

    const sort = sorting[0]?.id as LeadSortColumn | undefined;
    const direction = sorting[0] ? (sorting[0].desc ? 'desc' : 'asc') : undefined;

    const { data, isPending, isFetching, isError } = useLeads({
        page, sort, direction, filters: { status: statusFilter || undefined },
    });
    const deleteLead = useDeleteLead();

    const columns = useMemo(
        () => [
            columnHelper.accessor('name', { header: 'Name', enableSorting: false }),
            columnHelper.accessor('company', { header: 'Company' }),
            columnHelper.accessor('status', { header: 'Status', cell: (i) => <span className="capitalize">{i.getValue()}</span> }),
            columnHelper.accessor('owner', { header: 'Owner', cell: (i) => i.getValue()?.name ?? 'Unassigned', enableSorting: false }),
            columnHelper.accessor('created_at', { header: 'Created', cell: (i) => (i.getValue() ? new Date(i.getValue()!).toLocaleDateString() : '-') }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: (info) => {
                    const lead = info.row.original;
                    const converted = lead.status === 'converted';
                    return (
                        <div className="flex gap-2 text-sm">
                            <button onClick={() => onEdit(lead)} className="text-indigo-600 hover:underline">{converted ? 'View' : 'Edit'}</button>
                            {!converted && (
                                <button
                                    onClick={() => { if (confirm(`Delete lead "${lead.name}"?`)) deleteLead.mutate(lead.id); }}
                                    className="text-red-600 hover:underline"
                                >
                                    Delete
                                </button>
                            )}
                        </div>
                    );
                },
            }),
        ],
        [onEdit, deleteLead],
    );

    const table = useReactTable({
        data: data?.data ?? [],
        columns,
        state: { sorting },
        onSortingChange: setSorting,
        manualSorting: true,
        manualPagination: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (isError) return <p className="text-sm text-red-600">Couldn't load leads. Try refreshing.</p>;

    return (
        <div>
            <div className="mb-3 flex items-center gap-2">
                <label htmlFor="status-filter" className="text-sm font-medium">Status</label>
                <select
                    id="status-filter"
                    value={statusFilter}
                    onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
                    className="rounded-md border px-2 py-1 text-sm"
                >
                    <option value="">All</option>
                    {LEAD_FILTERABLE_STATUSES.map((s) => <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>)}
                </select>
                {isFetching && <span className="text-xs text-[var(--color-ink-muted)]">Refreshing...</span>}
            </div>

            <table className="w-full text-left text-sm">
                <thead>
                    {table.getHeaderGroups().map((hg) => (
                        <tr key={hg.id} className="border-b">
                            {hg.headers.map((header) => {
                                const canSort = LEAD_SORTABLE_COLUMNS.includes(header.column.id as LeadSortColumn);
                                return (
                                    <th
                                        key={header.id}
                                        onClick={canSort ? header.column.getToggleSortingHandler() : undefined}
                                        className={`py-2 pr-4 font-medium ${canSort ? 'cursor-pointer select-none' : ''}`}
                                    >
                                        {flexRender(header.column.columnDef.header, header.getContext())}
                                        {{ asc: ' ^', desc: ' v' }[header.column.getIsSorted() as string] ?? ''}
                                    </th>
                                );
                            })}
                        </tr>
                    ))}
                </thead>
                <tbody>
                    {isPending ? (
                        <tr><td colSpan={columns.length} className="py-4 text-center text-[var(--color-ink-muted)]">Loading...</td></tr>
                    ) : table.getRowModel().rows.length === 0 ? (
                        <tr><td colSpan={columns.length} className="py-4 text-center text-[var(--color-ink-muted)]">No leads found.</td></tr>
                    ) : (
                        table.getRowModel().rows.map((row) => (
                            <tr key={row.id} className="border-b last:border-0">
                                {row.getVisibleCells().map((cell) => (
                                    <td key={cell.id} className="py-2 pr-4">{flexRender(cell.column.columnDef.cell, cell.getContext())}</td>
                                ))}
                            </tr>
                        ))
                    )}
                </tbody>
            </table>

            {data?.meta && (
                <div className="mt-3 flex items-center justify-between text-sm">
                    <span className="text-[var(--color-ink-muted)]">{data.meta.total} lead{data.meta.total === 1 ? '' : 's'}</span>
                    <div className="flex gap-2">
                        <button onClick={() => setPage((p) => Math.max(1, p - 1))} disabled={data.meta.current_page <= 1} className="rounded-md border px-2 py-1 disabled:opacity-50">Previous</button>
                        <button onClick={() => setPage((p) => p + 1)} disabled={data.meta.current_page >= data.meta.last_page} className="rounded-md border px-2 py-1 disabled:opacity-50">Next</button>
                    </div>
                </div>
            )}
        </div>
    );
}