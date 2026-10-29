import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useEmployees, useHrUnitOptions } from '../api/useHr';
import { validateEmployeesSearch, type Employee, type EmployeesSearch } from '../schemas/hr';
import { EmployeeFormModal } from './EmployeeFormModal';

const columnHelper = createColumnHelper<Employee>();
const EMPTY: Employee[] = [];

function StatusBadge({ active }: { active: boolean }) {
    return <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-700'}`}>{active ? 'Active' : 'Inactive'}</span>;
}

// 13.1c: employees list. Filters and page live in the URL (8.7).
export function EmployeesPage() {
    const allowed = usePermission('hr.view');
    const canManage = usePermission('hr.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateEmployeesSearch(raw);
    const page = search.page ?? 1;
    const [editing, setEditing] = useState<Employee | 'new' | null>(null);

    function setSearch(patch: Partial<EmployeesSearch>) {
        void navigate({ to: '/hr', search: validateEmployeesSearch({ ...search, ...patch }), replace: true });
    }

    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const teams = useHrUnitOptions('team', allowed);
    const territories = useHrUnitOptions('territory', allowed);
    const { data, isPending, isFetching, isError, error } = useEmployees({
        page,
        q: search.q,
        teamId: search.team_id,
        territoryId: search.territory_id,
        status: search.status,
        enabled: allowed,
    });

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
            columnHelper.accessor('job_title', { header: 'Job title', cell: (info) => info.getValue() ?? '' }),
            columnHelper.display({ id: 'team', header: 'Team', cell: ({ row }) => row.original.team?.name ?? '' }),
            columnHelper.display({ id: 'territory', header: 'Territory', cell: ({ row }) => row.original.territory?.name ?? '' }),
            columnHelper.accessor('email', { header: 'Email', cell: (info) => info.getValue() ?? '' }),
            columnHelper.accessor('is_active', { header: 'Status', cell: (info) => <StatusBadge active={info.getValue()} /> }),
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
    const hasFilters = !!(search.q || search.team_id || search.territory_id || search.status);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">Employees</h1>
                    <Link to="/hr/units" className="text-sm underline">
                        Teams &amp; territories
                    </Link>
                </div>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <button type="button" onClick={() => setEditing('new')} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                            New employee
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input type="search" placeholder="Search name, email or job title..." value={qInput} onChange={(e) => setQInput(e.target.value)} className="w-64 rounded-md border px-3 py-2 text-sm" />
                <select
                    aria-label="Filter by team"
                    value={search.team_id ? String(search.team_id) : ''}
                    onChange={(e) => setSearch({ team_id: e.target.value ? Number(e.target.value) : undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All teams</option>
                    {(teams.data ?? []).map((t) => (
                        <option key={t.id} value={t.id}>
                            {t.name}
                        </option>
                    ))}
                </select>
                <select
                    aria-label="Filter by territory"
                    value={search.territory_id ? String(search.territory_id) : ''}
                    onChange={(e) => setSearch({ territory_id: e.target.value ? Number(e.target.value) : undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All territories</option>
                    {(territories.data ?? []).map((t) => (
                        <option key={t.id} value={t.id}>
                            {t.name}
                        </option>
                    ))}
                </select>
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
                            void navigate({ to: '/hr', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load employees.")}
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
                                    {hasFilters ? 'No employees match these filters.' : 'No employees yet.'}
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

            <EmployeeFormModal open={editing !== null} employee={editing === 'new' ? null : editing} onClose={() => setEditing(null)} />
        </div>
    );
}
