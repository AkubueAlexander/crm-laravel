import { useEffect, useMemo, useRef } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useTenant } from '@/shared/hooks/useTenant';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { accountingRootKey } from '../api/useAccounting';
import { accountingFieldErrors } from '../api/useAccountingMutations';
import { useRunSync, useSaveSyncProvider } from '../api/useSyncMutations';
import { useSyncRuns, useSyncStatus } from '../api/useSync';
import { formatDateTime, syncProviderFormSchema, validateSyncSearch, type SyncProviderFormValues, type SyncRun } from '../schemas/sync';

const columnHelper = createColumnHelper<SyncRun>();
const EMPTY: SyncRun[] = [];

const STATUS_CLASS: Record<string, string> = {
    queued: 'bg-gray-100 text-gray-700',
    running: 'bg-blue-100 text-blue-800',
    succeeded: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
};

function StatusBadge({ status }: { status: string }) {
    return <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_CLASS[status] ?? STATUS_CLASS.queued}`}>{status}</span>;
}

// 12.2c: ledger sync to the books of record. Page lives in the URL (8.7).
export function SyncPage() {
    const allowed = usePermission('accounting.view');
    const canManage = usePermission('accounting.manage');
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const page = validateSyncSearch(raw).page ?? 1;

    const status = useSyncStatus(allowed);
    const active = status.data?.active_run ?? null;
    const runs = useSyncRuns({ page, enabled: allowed, poll: !!active });
    const saveProvider = useSaveSyncProvider();
    const runSync = useRunSync();

    const form = useForm<SyncProviderFormValues>({
        resolver: zodResolver(syncProviderFormSchema),
        defaultValues: { provider: '' },
    });
    const currentProvider = status.data?.provider;
    useEffect(() => {
        if (currentProvider) form.reset({ provider: currentProvider });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [currentProvider]);

    // When a run finishes, refresh the history once more (the last poll tick may predate the final state).
    const wasActive = useRef(false);
    useEffect(() => {
        const isActive = !!active;
        if (wasActive.current && !isActive) void queryClient.invalidateQueries({ queryKey: accountingRootKey(tenant?.id) });
        wasActive.current = isActive;
    }, [active, queryClient, tenant?.id]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('status', { header: 'Status', cell: (info) => <StatusBadge status={info.getValue()} /> }),
            columnHelper.accessor('provider', { header: 'Provider' }),
            columnHelper.display({
                id: 'counts',
                header: 'Synced',
                cell: ({ row }) => (
                    <span className="text-xs">
                        {row.original.accounts_synced} accounts, {row.original.entries_synced} entries
                    </span>
                ),
            }),
            columnHelper.accessor('started_at', { header: 'Started', cell: (info) => formatDateTime(info.getValue() ?? info.row.original.created_at) }),
            columnHelper.accessor('finished_at', { header: 'Finished', cell: (info) => formatDateTime(info.getValue()) }),
            columnHelper.accessor('error', {
                header: 'Error',
                cell: (info) => (info.getValue() ? <span className="text-xs text-red-700">{info.getValue()}</span> : null),
            }),
        ],
        [],
    );

    const table = useReactTable({
        data: runs.data?.data ?? EMPTY,
        columns,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to accounting.</p>;

    const providers = status.data?.providers ?? [];
    const selectedId = form.watch('provider');
    const selected = providers.find((p) => p.id === selectedId);
    const meta = runs.data?.meta;
    const runErrors = runSync.isError ? accountingFieldErrors(runSync.error) : {};
    const runMessage = runSync.isError ? (runErrors.sync ?? apiErrorMessage(runSync.error, "Couldn't start the sync.")) : null;

    function setPage(next: number) {
        void navigate({ to: '/accounting/sync', search: next > 1 ? { page: next } : {}, replace: true });
    }

    const onSave = form.handleSubmit((values) => {
        saveProvider.mutate(values.provider, {
            onError: (err) => {
                const fe = accountingFieldErrors(err);
                if (fe.provider) form.setError('provider', { message: fe.provider });
                else form.setError('root', { message: apiErrorMessage(err, "Couldn't save the provider.") });
            },
        });
    });

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/accounting" className="text-[var(--color-ink-muted)] underline">
                    Journal
                </Link>
            </div>
            <h1 className="mb-3 text-lg font-medium text-[var(--color-ink)]">Ledger sync</h1>
            <p role="note" className="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900">
                Sync pushes this operational ledger to your books of record (QuickBooks/Xero). It does not replace them.
            </p>

            {status.isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(status.error, "Couldn't load the sync status.")}
                </p>
            )}

            <section className="mb-6 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4">
                <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
                    <div className="text-sm">
                        <span className="text-[var(--color-ink-muted)]">Last synced at: </span>
                        <span className="font-medium">{status.isPending ? '...' : status.data?.last_synced_at ? formatDateTime(status.data.last_synced_at) : 'Never'}</span>
                    </div>
                    <div className="flex items-center gap-3">
                        {active && (
                            <span role="status" className="flex items-center gap-2 text-sm text-blue-800">
                                <StatusBadge status={active.status} /> Sync in progress...
                            </span>
                        )}
                        {canManage && (
                            <button
                                type="button"
                                disabled={!!active || runSync.isPending || status.isPending}
                                onClick={() => runSync.mutate()}
                                className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-40"
                            >
                                {runSync.isPending ? 'Starting...' : 'Sync now'}
                            </button>
                        )}
                    </div>
                </div>
                {runMessage && (
                    <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {runMessage}
                    </p>
                )}

                <form onSubmit={onSave} className="flex flex-wrap items-end gap-3" noValidate>
                    <label className="flex flex-col gap-1 text-sm">
                        Provider
                        <select {...form.register('provider')} disabled={!canManage || providers.length === 0} className="rounded-md border px-2 py-2 text-sm">
                            {providers.map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.label}
                                    {p.configured ? '' : ' (not connected)'}
                                </option>
                            ))}
                        </select>
                    </label>
                    {canManage && (
                        <button
                            type="submit"
                            disabled={saveProvider.isPending || !form.formState.isDirty}
                            className="rounded-md border px-3 py-2 text-sm hover:bg-[var(--color-paper)] disabled:opacity-40"
                        >
                            {saveProvider.isPending ? 'Saving...' : 'Save provider'}
                        </button>
                    )}
                </form>
                {form.formState.errors.provider && <p className="mt-2 text-sm text-red-700">{form.formState.errors.provider.message}</p>}
                {form.formState.errors.root && <p className="mt-2 text-sm text-red-700">{form.formState.errors.root.message}</p>}
                {selected && !selected.configured && (
                    <p role="note" className="mt-3 text-sm text-[var(--color-ink-muted)]">
                        {selected.label} is not connected yet. Runs with this provider will fail until it is set up.
                    </p>
                )}
            </section>

            <h2 className="mb-2 text-base font-medium text-[var(--color-ink)]">Run history</h2>
            {runs.isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(runs.error, "Couldn't load the run history.")}
                </p>
            )}
            {runs.isPending ? (
                <p className="text-sm text-gray-500">Loading...</p>
            ) : (
                <table className="w-full border-collapse text-sm">
                    <thead>
                        {table.getHeaderGroups().map((hg) => (
                            <tr key={hg.id}>
                                {hg.headers.map((h) => (
                                    <th key={h.id} className="border-b px-3 py-2 text-left font-medium">
                                        {h.isPlaceholder ? null : flexRender(h.column.columnDef.header, h.getContext())}
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
                                    No sync runs yet.
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
                        <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-md border px-2 py-1 disabled:opacity-40">
                            Previous
                        </button>
                        <button type="button" disabled={page >= meta.last_page} onClick={() => setPage(page + 1)} className="rounded-md border px-2 py-1 disabled:opacity-40">
                            Next
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
