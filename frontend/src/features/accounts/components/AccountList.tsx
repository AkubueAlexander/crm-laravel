import { useState } from 'react';
import {
    createColumnHelper,
    flexRender,
    getCoreRowModel,
    useReactTable,
    type SortingState,
} from '@tanstack/react-table';
import { useAccounts } from '../api/useAccounts';
import type { Account, AccountSortColumn } from '../schemas/account';

const columnHelper = createColumnHelper<Account>();

// Only name/industry/created_at are sortable server-side (ListAccountsRequest::SORTABLE),
// so every other column has sorting disabled rather than sending a 422-bound sort param.
const columns = [
    columnHelper.accessor('name', { header: 'Name' }),
    columnHelper.accessor('industry', { header: 'Industry', cell: (info) => info.getValue() ?? '\u2014' }),
    columnHelper.accessor('website', {
        header: 'Website',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '\u2014',
    }),
    columnHelper.accessor('phone', {
        header: 'Phone',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '\u2014',
    }),
    columnHelper.accessor((row) => row.owner?.name ?? null, {
        id: 'owner',
        header: 'Owner',
        enableSorting: false,
        cell: (info) => info.getValue() ?? '\u2014',
    }),
    columnHelper.accessor('contacts_count', { header: 'Contacts', enableSorting: false }),
];

type AccountListProps = {
    onSelectAccount?: (account: Account) => void;
};

/** 8a.1/8.4: server-side pagination/sorting via manualPagination/manualSorting. */
export function AccountList({ onSelectAccount }: AccountListProps) {
    const [page, setPage] = useState(1);
    const [sorting, setSorting] = useState<SortingState>([{ id: 'name', desc: false }]);
    const [search, setSearch] = useState('');

    const sort = (sorting[0]?.id ?? 'name') as AccountSortColumn;
    const direction = sorting[0]?.desc ? 'desc' : 'asc';

    const { data, isPending, isFetching, isError } = useAccounts({ page, sort, direction, q: search });

    const table = useReactTable({
        data: data?.data ?? [],
        columns,
        state: { sorting },
        onSortingChange: (updater) => {
            setSorting((old) => (typeof updater === 'function' ? updater(old) : updater));
            setPage(1);
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
                    placeholder="Search accounts..."
                    value={search}
                    onChange={(e) => {
                        setSearch(e.target.value);
                        setPage(1);
                    }}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing{'\u2026'}</span>}
            </div>

            {isError && <p className="text-sm text-red-600">Couldn't load accounts.</p>}

            {isPending ? (
                <p className="text-sm text-gray-500">Loading{'\u2026'}</p>
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
                                onClick={onSelectAccount ? () => onSelectAccount(row.original) : undefined}
                                className={onSelectAccount ? 'cursor-pointer hover:bg-gray-50' : ''}
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
                                    No accounts found.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            )}

            {meta && (
                <div className="mt-3 flex items-center justify-between text-sm text-gray-600">
                    <span>
                        {meta.from ?? 0}{'\u2013'}{meta.to ?? 0} of {meta.total}
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