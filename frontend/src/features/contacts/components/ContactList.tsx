import { useState } from 'react';
import {
    createColumnHelper,
    flexRender,
    getCoreRowModel,
    useReactTable,
    type SortingState,
} from '@tanstack/react-table';
import { useContacts } from '../api/useContacts';
import type { Contact, ContactSortColumn } from '../schemas/contact';

const columnHelper = createColumnHelper<Contact>();

const columns = [
    columnHelper.accessor('last_name', { header: 'Last name' }),
    columnHelper.accessor('first_name', { header: 'First name', cell: (info) => info.getValue() ?? '\u2014' }),
    columnHelper.accessor('email', { header: 'Email', cell: (info) => info.getValue() ?? '\u2014' }),
    columnHelper.accessor('phone', { header: 'Phone', cell: (info) => info.getValue() ?? '\u2014' }),
    columnHelper.accessor('job_title', { header: 'Job title', cell: (info) => info.getValue() ?? '\u2014' }),
];

type ContactListProps = {
    onSelectContact?: (contact: Contact) => void;
};

/**
 * 5.2/8.4: server-side pagination/sorting via manualPagination/manualSorting.
 * Row data always comes from TanStack Query; sort/page/search stay local table
 * state here. 8.7's URL persistence is the route's job to wire in later.
 */
export function ContactList({ onSelectContact }: ContactListProps) {
    const [page, setPage] = useState(1);
    const [sorting, setSorting] = useState<SortingState>([{ id: 'last_name', desc: false }]);
    const [search, setSearch] = useState('');

    const sort = (sorting[0]?.id ?? 'last_name') as ContactSortColumn;
    const direction = sorting[0]?.desc ? 'desc' : 'asc';

    const { data, isPending, isFetching, isError } = useContacts({ page, sort, direction, q: search });

    const table = useReactTable({
        data: data?.data ?? [],
        columns,
        state: { sorting },
        onSortingChange: (updater) => {
            setSorting((old) => (typeof updater === 'function' ? updater(old) : updater));
            setPage(1); // sort change resets to page 1
        },
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    const meta = data?.meta;

    return (
        <div>
            <div className="mb-3 flex items-center justify-between">
                <input
                    type="search"
                    placeholder="Search contacts..."
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing\u2026</span>}
            </div>

            {isError && <p className="text-sm text-red-600">Couldn't load contacts.</p>}

            {isPending ? (
                <p className="text-sm text-gray-500">Loading\u2026</p>
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
                                            {header.isPlaceholder
                                                ? null
                                                : flexRender(header.column.columnDef.header, header.getContext())}
                                            {sortDir === 'asc' ? ' \u25b2' : sortDir === 'desc' ? ' \u25bc' : ''}
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.map((row) => (
                            <tr
                                key={row.id}
                                onClick={() => onSelectContact?.(row.original)}
                                className="cursor-pointer hover:bg-gray-50"
                            >
                                {row.getVisibleCells().map((cell) => (
                                    <td key={cell.id} className="border-b px-3 py-2">
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {table.getRowModel().rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="px-3 py-6 text-center text-gray-500">
                                    No contacts found.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            )}

            {meta && (
                <div className="mt-3 flex items-center justify-between text-sm text-gray-600">
                    <span>
                        {meta.from ?? 0}\u2013{meta.to ?? 0} of {meta.total}
                    </span>
                    <div className="flex gap-2">
                        <button
                            type="button"
                            disabled={page <= 1}
                            onClick={() => setPage((p) => p - 1)}
                            className="rounded-md border px-2 py-1 disabled:opacity-40"
                        >
                            Previous
                        </button>
                        <button
                            type="button"
                            disabled={page >= meta.last_page}
                            onClick={() => setPage((p) => p + 1)}
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