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
import { useAutomations } from '../api/useAutomations';
import { useAutomationCatalog } from '../api/useAutomationCatalog';
import { useDeleteAutomation } from '../api/useDeleteAutomation';
import { transitionAutomationErrorMessage, useTransitionAutomation } from '../api/useTransitionAutomation';
import {
    AUTOMATION_STATUS_LABELS,
    validateAutomationsSearch,
    type Automation,
    type AutomationSortColumn,
    type AutomationStatus,
    type AutomationsSearch,
} from '../schemas/automation';
import { AutomationRowActions, NewAutomationButton } from './AutomationRowActions';
import { ConfirmDeleteAutomationDialog } from './ConfirmDeleteAutomationDialog';
import { MarketingTabs } from './MarketingTabs';

const columnHelper = createColumnHelper<Automation>();
const EMPTY: Automation[] = [];
const DASH = '\u2014';

const STATUS_CLASS: Record<AutomationStatus, string> = {
    draft: 'border-gray-300 text-gray-600',
    active: 'border-green-300 text-green-700',
    paused: 'border-amber-300 text-amber-700',
};

function formatDate(value: string | null): string {
    if (!value) return DASH;
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? DASH : d.toLocaleDateString();
}

export function AutomationsPage() {
    const allowed = usePermission('marketing.view');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateAutomationsSearch(raw);

    const page = search.page ?? 1;
    const sort: AutomationSortColumn = search.sort ?? 'created_at';
    const direction = search.direction ?? 'desc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<AutomationsSearch>) {
        void navigate({ to: '/marketing/automations', search: validateAutomationsSearch({ ...search, ...patch }), replace: true });
    }

    // Search box: local state for typing, debounced into the URL (and from there into the query key).
    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useAutomations({
        page,
        sort,
        direction,
        q: search.q,
        enabled: allowed,
    });
    const catalog = useAutomationCatalog();

    const triggerLabels = useMemo(
        () => new Map<string, string>((catalog.data?.data.triggers ?? []).map((t) => [t.type, t.label] as [string, string])),
        [catalog.data],
    );

    // After deleting the last row of the last page, step back instead of showing an empty page.
    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const del = useDeleteAutomation();
    const transition = useTransitionAutomation();
    const [pendingDelete, setPendingDelete] = useState<Automation | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);

    function changeStatus(automation: Automation, toStatus: AutomationStatus) {
        setActionError(null);
        transition.mutate(
            { automation, toStatus },
            { onError: (err) => setActionError(transitionAutomationErrorMessage(err)) },
        );
    }

    const columns = useMemo(
        () => [
            columnHelper.accessor('name', {
                header: 'Automation',
                cell: (info) => (
                    <div>
                        <div className="font-medium">{info.getValue()}</div>
                        {info.row.original.description && (
                            <div className="text-xs text-[var(--color-ink-muted)]">{info.row.original.description}</div>
                        )}
                    </div>
                ),
            }),
            columnHelper.display({
                id: 'trigger',
                header: 'Trigger',
                cell: ({ row }) => triggerLabels.get(row.original.trigger_type) ?? row.original.trigger_type.replace(/_/g, ' '),
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'steps',
                header: 'Steps',
                cell: ({ row }) => {
                    const steps = row.original.steps;
                    const emails = steps.filter((s) => s.type === 'send_email').length;
                    return (
                        <div>
                            <div>{steps.length === 1 ? '1 step' : `${steps.length} steps`}</div>
                            <div className="text-xs text-[var(--color-ink-muted)]">{emails === 1 ? '1 email' : `${emails} emails`}</div>
                        </div>
                    );
                },
                enableSorting: false,
            }),
            columnHelper.accessor('status', {
                header: 'Status',
                cell: (info) => (
                    <span className={`rounded-full border px-2 py-0.5 text-xs ${STATUS_CLASS[info.getValue()]}`}>
                        {AUTOMATION_STATUS_LABELS[info.getValue()]}
                    </span>
                ),
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
                cell: ({ row }) => (
                    <AutomationRowActions
                        automation={row.original}
                        busy={transition.isPending}
                        onTransition={(to) => changeStatus(row.original, to)}
                        onDelete={() => {
                            del.reset();
                            setPendingDelete(row.original);
                        }}
                    />
                ),
                enableSorting: false,
            }),
        ],
        // changeStatus, del.reset and setPendingDelete are stable enough for a table whose rows re-render with the data.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [triggerLabels, transition.isPending],
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
            setSearch({ sort: first.id as AutomationSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
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
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Automations</h1>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    <NewAutomationButton />
                </div>
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
                            void navigate({ to: '/marketing/automations', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {actionError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {actionError}
                </p>
            )}

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load automations.")}
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
                                    {hasFilters ? 'No automations match this search.' : 'No automations yet.'}
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

            <ConfirmDeleteAutomationDialog
                automation={pendingDelete}
                isPending={del.isPending}
                error={del.isError ? apiErrorMessage(del.error, 'Could not delete the automation. Please try again.') : null}
                onConfirm={confirmDelete}
                onClose={() => {
                    del.reset();
                    setPendingDelete(null);
                }}
            />
        </div>
    );
}