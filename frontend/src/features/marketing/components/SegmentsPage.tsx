import { useEffect, useMemo, useState } from 'react';
import {
    createColumnHelper,
    flexRender,
    getCoreRowModel,
    useReactTable,
    type SortingState,
} from '@tanstack/react-table';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useSegments } from '../api/useSegments';
import { useDeleteSegment } from '../api/useDeleteSegment';
import {
    validateSegmentsSearch,
    type Segment,
    type SegmentCondition,
    type SegmentSortColumn,
    type SegmentsSearch,
} from '../schemas/segment';
import { ConfirmDeleteSegmentDialog } from './ConfirmDeleteSegmentDialog';
import { MarketingTabs } from './MarketingTabs';

const columnHelper = createColumnHelper<Segment>();
const EMPTY: Segment[] = [];
const DASH = '\u2014';

function formatDate(value: string | null): string {
    if (!value) return DASH;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? DASH : d.toLocaleDateString();
}

// A readable one-liner from the raw stored condition, e.g. email contains "acme". The builder (9.2d) uses
// the server's field catalog for proper labels; the list only needs a glanceable hint.
function summarize(condition: SegmentCondition): string {
    const field = condition.field.replace(/_/g, ' ');
    const operator = condition.operator.replace(/_/g, ' ');
    return condition.value === null ? `${field} ${operator}` : `${field} ${operator} "${condition.value}"`;
}

export function SegmentsPage() {
    const allowed = usePermission('marketing.view');
    const canManage = usePermission('marketing.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateSegmentsSearch(raw);

    const page = search.page ?? 1;
    const sort: SegmentSortColumn = search.sort ?? 'created_at';
    const direction = search.direction ?? 'desc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<SegmentsSearch>) {
        void navigate({ to: '/marketing/segments', search: validateSegmentsSearch({ ...search, ...patch }), replace: true });
    }

    // Search box: local state for typing, debounced into the URL (and from there into the query key).
    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useSegments({
        page,
        sort,
        direction,
        q: search.q,
        enabled: allowed,
    });

    // After deleting the last row of the last page, step back instead of showing an empty page.
    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const del = useDeleteSegment();
    const [pendingDelete, setPendingDelete] = useState<Segment | null>(null);

    const columns = useMemo(
        () => [
            columnHelper.accessor('name', {
                header: 'Segment',
                cell: (info) => (
                    <div>
                        <div className="font-medium">{info.getValue()}</div>
                        {info.row.original.description && (
                            <div className="text-xs text-[var(--color-ink-muted)]">{info.row.original.description}</div>
                        )}
                    </div>
                ),
            }),
            columnHelper.accessor('match_mode', {
                header: 'Match',
                cell: (info) => (info.getValue() === 'all' ? 'All conditions' : 'Any condition'),
            }),
            columnHelper.display({
                id: 'conditions',
                header: 'Conditions',
                cell: ({ row }) => {
                    const list = row.original.conditions;
                    const shown = list.slice(0, 2).map(summarize);
                    const more = list.length - shown.length;
                    return (
                        <div>
                            <div>{list.length === 1 ? '1 condition' : `${list.length} conditions`}</div>
                            <div className="text-xs text-[var(--color-ink-muted)]">
                                {shown.join('; ')}
                                {more > 0 ? `; +${more} more` : ''}
                            </div>
                        </div>
                    );
                },
                enableSorting: false,
            }),
            columnHelper.accessor('created_at', {
                header: 'Created',
                cell: (info) => formatDate(info.getValue()),
            }),
            columnHelper.accessor('updated_at', {
                header: 'Updated',
                cell: (info) => formatDate(info.getValue()),
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => {
                    if (!canManage) return null;
                    const s = row.original;
                    return (
                        <button
                            type="button"
                            onClick={() => {
                                del.reset();
                                setPendingDelete(s);
                            }}
                            className="rounded-md border px-2 py-1 text-xs text-red-700"
                        >
                            Delete
                        </button>
                    );
                },
                enableSorting: false,
            }),
        ],
        // del.reset is stable; setPendingDelete is a state setter.
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
            setSearch({ sort: first.id as SegmentSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to marketing.</p>;
    }

    const meta = data?.meta;
    const hasFilters = !!search.q;

    function confirmDelete() {
        if (!pendingDelete) return;
        del.mutate(pendingDelete.id, { onSuccess: () => setPendingDelete(null) });
    }

    return (
        <div>
            <MarketingTabs />

            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Segments</h1>
                {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    placeholder="Search name or description..."
                    value={qInput}
                    onChange={(e) => setQInput(e.target.value)}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/marketing/segments', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load segments.")}
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
                                    {hasFilters ? 'No segments match this search.' : 'No segments yet.'}
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
                        <button
                            type="button"
                            disabled={page <= 1}
                            onClick={() => setSearch({ page: page - 1 })}
                            className="rounded-md border px-2 py-1 disabled:opacity-40"
                        >
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

            <ConfirmDeleteSegmentDialog
                segment={pendingDelete}
                isPending={del.isPending}
                error={del.isError ? apiErrorMessage(del.error, 'Could not delete the segment. Please try again.') : null}
                onConfirm={confirmDelete}
                onClose={() => {
                    del.reset();
                    setPendingDelete(null);
                }}
            />
        </div>
    );
}
