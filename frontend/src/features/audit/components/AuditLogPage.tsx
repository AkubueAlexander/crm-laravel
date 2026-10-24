import { useMemo } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useAuditLog } from '../api/useAuditLog';
import { useAuditActors } from '../api/useAuditActors';
import { describeAuditEntry, sourceLabel } from '../lib/describe';
import { validateAuditSearch, type AuditEntry, type AuditSearch, type AuditSource } from '../schemas/audit';

const columnHelper = createColumnHelper<AuditEntry>();
const EMPTY: AuditEntry[] = [];
const DASH = '\u2014';

export function AuditLogPage() {
    const allowed = usePermission('audit.view');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateAuditSearch(raw);
    const page = search.page ?? 1;

    // Single place that writes filters/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<AuditSearch>) {
        void navigate({ to: '/audit', search: validateAuditSearch({ ...search, ...patch }), replace: true });
    }

    const { data, isPending, isFetching, isError, error } = useAuditLog({
        page,
        source: search.source,
        userId: search.user,
        from: search.from,
        to: search.to,
        enabled: allowed,
    });
    const { data: actors = [] } = useAuditActors(allowed);

    const columns = useMemo(
        () => [
            columnHelper.accessor('occurred_at', {
                header: 'When',
                cell: (info) => {
                    const d = new Date(info.getValue());
                    return <span className="whitespace-nowrap">{Number.isNaN(d.getTime()) ? DASH : d.toLocaleString()}</span>;
                },
            }),
            columnHelper.display({
                id: 'actor',
                header: 'Who',
                cell: ({ row }) => row.original.actor?.name ?? 'System',
            }),
            columnHelper.accessor('source', {
                header: 'Area',
                cell: (info) => sourceLabel(info.getValue()),
            }),
            columnHelper.display({
                id: 'what',
                header: 'What',
                cell: ({ row }) => describeAuditEntry(row.original),
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

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to the audit log.</p>;
    }

    const meta = data?.meta;
    const hasFilters = !!(search.source || search.user || search.from || search.to);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Audit log</h1>
                {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
            </div>

            <div className="mb-3 flex flex-wrap items-end gap-3">
                <label className="flex flex-col gap-1 text-xs text-[var(--color-ink-muted)]">
                    Area
                    <select
                        aria-label="Filter by area"
                        value={search.source ?? ''}
                        onChange={(e) => setSearch({ source: (e.target.value || undefined) as AuditSource | undefined, page: undefined })}
                        className="rounded-md border px-2 py-2 text-sm text-[var(--color-ink)]"
                    >
                        <option value="">All areas</option>
                        <option value="deal_stage">Deals</option>
                        <option value="users">Users</option>
                    </select>
                </label>

                <label className="flex flex-col gap-1 text-xs text-[var(--color-ink-muted)]">
                    User
                    <select
                        aria-label="Filter by user"
                        value={search.user ? String(search.user) : ''}
                        onChange={(e) => setSearch({ user: e.target.value ? Number(e.target.value) : undefined, page: undefined })}
                        className="rounded-md border px-2 py-2 text-sm text-[var(--color-ink)]"
                    >
                        <option value="">Everyone</option>
                        {search.user && !actors.some((a) => a.id === search.user) && (
                            <option value={String(search.user)}>User #{search.user}</option>
                        )}
                        {actors.map((a) => (
                            <option key={a.id} value={String(a.id)}>
                                {a.name}
                            </option>
                        ))}
                    </select>
                </label>

                <label className="flex flex-col gap-1 text-xs text-[var(--color-ink-muted)]">
                    From
                    <input
                        type="date"
                        aria-label="From date"
                        value={search.from ?? ''}
                        max={search.to}
                        onChange={(e) => setSearch({ from: e.target.value || undefined, page: undefined })}
                        className="rounded-md border px-2 py-2 text-sm text-[var(--color-ink)]"
                    />
                </label>

                <label className="flex flex-col gap-1 text-xs text-[var(--color-ink-muted)]">
                    To
                    <input
                        type="date"
                        aria-label="To date"
                        value={search.to ?? ''}
                        min={search.from}
                        onChange={(e) => setSearch({ to: e.target.value || undefined, page: undefined })}
                        className="rounded-md border px-2 py-2 text-sm text-[var(--color-ink)]"
                    />
                </label>

                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => void navigate({ to: '/audit', search: {}, replace: true })}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load the audit log.")}
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
                                    {hasFilters ? 'No events match these filters.' : 'No events recorded yet.'}
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
        </div>
    );
}
