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
import { useCampaigns } from '../api/useCampaigns';
import { useDeleteCampaign } from '../api/useDeleteCampaign';
import {
    CAMPAIGN_STATUSES,
    validateCampaignsSearch,
    type Campaign,
    type CampaignSortColumn,
    type CampaignStatus,
    type CampaignsSearch,
} from '../schemas/campaign';
import { ConfirmDeleteCampaignDialog } from './ConfirmDeleteCampaignDialog';
import { CampaignFormDialog } from './CampaignFormDialog';
import { MarketingTabs } from './MarketingTabs';

const columnHelper = createColumnHelper<Campaign>();
const EMPTY: Campaign[] = [];
const DASH = '\u2014';

const STATUS_STYLES: Record<CampaignStatus, string> = {
    draft: 'bg-gray-100 text-gray-700',
    scheduled: 'bg-blue-50 text-blue-700',
    sending: 'bg-amber-50 text-amber-700',
    sent: 'bg-green-50 text-green-700',
    cancelled: 'bg-red-50 text-red-700',
};

function StatusBadge({ status }: { status: CampaignStatus }) {
    return (
        <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium capitalize ${STATUS_STYLES[status]}`}>
            {status}
        </span>
    );
}

function formatDate(value: string | null): string {
    if (!value) return DASH;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? DASH : d.toLocaleDateString();
}

export function CampaignsPage() {
    const allowed = usePermission('marketing.view');
    const canManage = usePermission('marketing.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateCampaignsSearch(raw);

    const page = search.page ?? 1;
    const sort: CampaignSortColumn = search.sort ?? 'created_at';
    const direction = search.direction ?? 'desc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<CampaignsSearch>) {
        void navigate({ to: '/marketing', search: validateCampaignsSearch({ ...search, ...patch }), replace: true });
    }

    // Search box: local state for typing, debounced into the URL (and from there into the query key).
    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useCampaigns({
        page,
        sort,
        direction,
        q: search.q,
        status: search.status,
        enabled: allowed,
    });

    // After deleting the last row of the last page, step back instead of showing an empty page.
    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const del = useDeleteCampaign();
    const [pendingDelete, setPendingDelete] = useState<Campaign | null>(null);
    // 9.1c: null = closed, 'new' = create dialog, Campaign = edit dialog.
    const [formTarget, setFormTarget] = useState<Campaign | 'new' | null>(null);

    const columns = useMemo(
        () => [
            columnHelper.accessor('name', {
                header: 'Campaign',
                cell: (info) => (
                    <div>
                        <div className="font-medium">{info.getValue()}</div>
                        {info.row.original.subject && (
                            <div className="text-xs text-[var(--color-ink-muted)]">{info.row.original.subject}</div>
                        )}
                    </div>
                ),
            }),
            columnHelper.accessor('status', {
                header: 'Status',
                cell: (info) => <StatusBadge status={info.getValue()} />,
            }),
            columnHelper.accessor('recipients_count', {
                header: 'Recipients',
                cell: (info) => info.getValue().toLocaleString(),
            }),
            columnHelper.display({
                id: 'sent',
                header: 'Sent',
                cell: ({ row }) => (row.original.status === 'draft' ? DASH : row.original.sent_count.toLocaleString()),
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'opened',
                header: 'Opened',
                cell: ({ row }) => (row.original.status === 'draft' ? DASH : row.original.opened_count.toLocaleString()),
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'clicked',
                header: 'Clicked',
                cell: ({ row }) => (row.original.status === 'draft' ? DASH : row.original.clicked_count.toLocaleString()),
                enableSorting: false,
            }),
            columnHelper.accessor('created_at', {
                header: 'Created',
                cell: (info) => formatDate(info.getValue()),
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => {
                    const c = row.original;
                    if (!canManage || !c.editable) return null;
                    return (
                        <div className="flex gap-2">
                            <button
                                type="button"
                                onClick={() => setFormTarget(c)}
                                className="rounded-md border px-2 py-1 text-xs"
                            >
                                Edit
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    del.reset();
                                    setPendingDelete(c);
                                }}
                                className="rounded-md border px-2 py-1 text-xs text-red-700"
                            >
                                Delete
                            </button>
                        </div>
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
            setSearch({ sort: first.id as CampaignSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
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
    const hasFilters = !!(search.q || search.status);

    function confirmDelete() {
        if (!pendingDelete) return;
        del.mutate(pendingDelete.id, { onSuccess: () => setPendingDelete(null) });
    }

    return (
        <div>
            <MarketingTabs />

            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Campaigns</h1>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => setFormTarget('new')}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90"
                        >
                            New campaign
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    placeholder="Search name or subject..."
                    value={qInput}
                    onChange={(e) => setQInput(e.target.value)}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                <select
                    aria-label="Filter by status"
                    value={search.status ?? ''}
                    onChange={(e) => setSearch({ status: (e.target.value || undefined) as CampaignStatus | undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All statuses</option>
                    {CAMPAIGN_STATUSES.map((s) => (
                        <option key={s} value={s} className="capitalize">
                            {s.charAt(0).toUpperCase() + s.slice(1)}
                        </option>
                    ))}
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/marketing', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load campaigns.")}
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
                                    {hasFilters ? 'No campaigns match these filters.' : 'No campaigns yet.'}
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

            <CampaignFormDialog target={formTarget} onClose={() => setFormTarget(null)} />

            <ConfirmDeleteCampaignDialog
                campaign={pendingDelete}
                isPending={del.isPending}
                error={del.isError ? apiErrorMessage(del.error, 'Could not delete the campaign. Please try again.') : null}
                onConfirm={confirmDelete}
                onClose={() => {
                    del.reset();
                    setPendingDelete(null);
                }}
            />
        </div>
    );
}
