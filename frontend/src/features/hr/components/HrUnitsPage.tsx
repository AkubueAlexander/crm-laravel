import { useEffect, useMemo, useState } from 'react';
import { useForm, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useHrUnits } from '../api/useHr';
import { hrFieldErrors, useSaveHrUnit } from '../api/useHrMutations';
import { HR_UNIT_LABELS, unitFormSchema, unitToForm, validateHrUnitsSearch, type HrUnit, type HrUnitKind, type HrUnitsSearch, type UnitFormValues } from '../schemas/hr';

const columnHelper = createColumnHelper<HrUnit>();
const EMPTY: HrUnit[] = [];
const INPUT = 'w-full rounded-md border px-3 py-2 text-sm';

function UnitFormModal({ kind, unit, open, onClose }: { kind: HrUnitKind; unit: HrUnit | null; open: boolean; onClose: () => void }) {
    const save = useSaveHrUnit();
    const label = HR_UNIT_LABELS[kind].singular;
    const form = useForm<UnitFormValues>({ resolver: zodResolver(unitFormSchema), defaultValues: unitToForm(unit) });

    useEffect(() => {
        if (open) {
            form.reset(unitToForm(unit));
            save.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, unit?.id, kind]);

    const { errors } = form.formState;

    const onSubmit = form.handleSubmit((values) => {
        save.mutate(
            { kind, unitId: unit?.id, values },
            {
                onSuccess: () => onClose(),
                onError: (err) => {
                    let mapped = false;
                    for (const [field, message] of Object.entries(hrFieldErrors(err))) {
                        if (field in values) {
                            form.setError(field as Path<UnitFormValues>, { message });
                            mapped = true;
                        }
                    }
                    if (!mapped) form.setError('root', { message: apiErrorMessage(err, `Couldn't save the ${label}.`) });
                },
            },
        );
    });

    return (
        <Modal open={open} onClose={onClose} title={unit ? `Edit ${label}` : `New ${label}`}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <label className="flex flex-col gap-1 text-sm">
                    Name
                    <input {...form.register('name')} className={INPUT} />
                    {errors.name && <span className="text-xs text-red-700">{errors.name.message}</span>}
                </label>
                <label className="flex flex-col gap-1 text-sm">
                    Description
                    <textarea {...form.register('description')} rows={3} className={INPUT} />
                    {errors.description && <span className="text-xs text-red-700">{errors.description.message}</span>}
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <input type="checkbox" {...form.register('is_active')} />
                    Active
                </label>
                {errors.root && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {errors.root.message}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-md border px-3 py-2 text-sm">
                        Cancel
                    </button>
                    <button type="submit" disabled={save.isPending} className="rounded-md bg-[var(--color-ink)] px-3 py-2 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {save.isPending ? 'Saving...' : 'Save'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

// 13.1c: teams and territories. The kind tab, filters and page live in the URL (8.7).
export function HrUnitsPage() {
    const allowed = usePermission('hr.view');
    const canManage = usePermission('hr.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateHrUnitsSearch(raw);
    const kind: HrUnitKind = search.kind ?? 'team';
    const page = search.page ?? 1;
    const labels = HR_UNIT_LABELS[kind];
    const [editing, setEditing] = useState<HrUnit | 'new' | null>(null);

    function setSearch(patch: Partial<HrUnitsSearch>) {
        void navigate({ to: '/hr/units', search: validateHrUnitsSearch({ ...search, ...patch }), replace: true });
    }

    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useHrUnits({ kind, page, q: search.q, status: search.status, enabled: allowed });

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('name', {
                header: 'Name',
                cell: (info) =>
                    canManage ? (
                        <button type="button" onClick={() => setEditing(info.row.original)} className="font-medium underline">
                            {info.getValue()}
                        </button>
                    ) : (
                        <span className="font-medium">{info.getValue()}</span>
                    ),
            }),
            columnHelper.accessor('description', { header: 'Description', cell: (info) => info.getValue() ?? '' }),
            columnHelper.accessor('is_active', {
                header: 'Status',
                cell: (info) => (
                    <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${info.getValue() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'}`}>
                        {info.getValue() ? 'Active' : 'Inactive'}
                    </span>
                ),
            }),
        ],
        [canManage],
    );

    const table = useReactTable({
        data: data?.data ?? EMPTY,
        columns,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to HR.</p>;

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.status);
    const tabClass = (active: boolean) => `rounded-md px-2 py-1 text-sm ${active ? 'bg-[var(--color-paper)] font-medium text-[var(--color-ink)]' : 'text-[var(--color-ink-muted)] hover:bg-[var(--color-paper)]'}`;

    return (
        <div>
            <div className="mb-1 text-sm">
                <Link to="/hr" className="text-[var(--color-ink-muted)] underline">
                    Employees
                </Link>
            </div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Teams &amp; territories</h1>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <button type="button" onClick={() => setEditing('new')} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                            New {labels.singular}
                        </button>
                    )}
                </div>
            </div>

            <nav className="mb-3 flex gap-1" aria-label="Kind">
                <button type="button" className={tabClass(kind === 'team')} onClick={() => { setQInput(''); void navigate({ to: '/hr/units', search: {}, replace: true }); }}>
                    Teams
                </button>
                <button type="button" className={tabClass(kind === 'territory')} onClick={() => { setQInput(''); void navigate({ to: '/hr/units', search: { kind: 'territory' }, replace: true }); }}>
                    Territories
                </button>
            </nav>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input type="search" placeholder={`Search ${labels.plural.toLowerCase()}...`} value={qInput} onChange={(e) => setQInput(e.target.value)} className="w-64 rounded-md border px-3 py-2 text-sm" />
                <select
                    aria-label="Filter by status"
                    value={search.status ?? ''}
                    onChange={(e) => setSearch({ status: e.target.value === 'active' || e.target.value === 'inactive' ? e.target.value : undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">Any status</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/hr/units', search: kind === 'territory' ? { kind: 'territory' } : {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, `Couldn't load ${labels.plural.toLowerCase()}.`)}
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
                                    {hasFilters ? `No ${labels.plural.toLowerCase()} match these filters.` : `No ${labels.plural.toLowerCase()} yet.`}
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

            <UnitFormModal kind={kind} unit={editing === 'new' ? null : editing} open={editing !== null} onClose={() => setEditing(null)} />
        </div>
    );
}
