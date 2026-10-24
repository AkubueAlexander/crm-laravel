import { useEffect, useMemo, useState } from 'react';
import {
    createColumnHelper,
    flexRender,
    getCoreRowModel,
    useReactTable,
    type SortingState,
} from '@tanstack/react-table';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useUsers } from '../api/useUsers';
import { useUserRoles } from '../api/useUserRoles';
import { useChangeUserRole, useSetUserActive } from '../api/useUserMutations';
import {
    validateUsersSearch,
    type ManagedUser,
    type UserSortColumn,
    type UserStatus,
    type UsersSearch,
} from '../schemas/user';
import { ConfirmUserStateDialog, type UserStateMode } from './ConfirmUserStateDialog';
import { InviteUserDialog } from './InviteUserDialog';
import { PendingInvitations } from './PendingInvitations';

const columnHelper = createColumnHelper<ManagedUser>();
const EMPTY: ManagedUser[] = [];
const DASH = '\u2014';

export function UsersPage() {
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateUsersSearch(raw);

    const page = search.page ?? 1;
    const sort: UserSortColumn = search.sort ?? 'name';
    const direction = search.direction ?? 'asc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<UsersSearch>) {
        void navigate({ to: '/users', search: validateUsersSearch({ ...search, ...patch }), replace: true });
    }

    // Search box: local state for typing, debounced into the URL (and from there into the query key).
    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError } = useUsers({
        page,
        sort,
        direction,
        q: search.q,
        role: search.role,
        status: search.status,
    });
    const { data: roles = [] } = useUserRoles();

    const changeRole = useChangeUserRole();
    const { mutate: changeRoleMutate } = changeRole;
    const changingId = changeRole.isPending ? changeRole.variables?.userId : undefined;
    const setActive = useSetUserActive();

    const [pendingState, setPendingState] = useState<{ user: ManagedUser; mode: UserStateMode } | null>(null);
    const [inviteOpen, setInviteOpen] = useState(false);

    const columns = useMemo(
        () => [
            columnHelper.accessor('name', {
                header: 'Name',
                cell: (info) => (
                    <span>
                        {info.getValue()}
                        {info.row.original.is_self && <span className="ml-1 text-xs text-[var(--color-ink-muted)]">(you)</span>}
                    </span>
                ),
            }),
            columnHelper.accessor('email', { header: 'Email' }),
            columnHelper.display({
                id: 'role',
                header: 'Role',
                cell: ({ row }) => {
                    const u = row.original;
                    const known = roles.some((r) => r.name === u.role);
                    return (
                        <select
                            aria-label={`Role for ${u.name}`}
                            value={u.role ?? ''}
                            disabled={u.is_self || !u.active || changingId === u.id}
                            onChange={(e) => changeRoleMutate({ userId: u.id, role: e.target.value })}
                            className="rounded-md border px-2 py-1 text-sm disabled:opacity-60"
                        >
                            {!u.role && (
                                <option value="" disabled>
                                    No role
                                </option>
                            )}
                            {u.role && !known && <option value={u.role}>{u.role_label ?? u.role}</option>}
                            {roles.map((r) => (
                                <option key={r.name} value={r.name}>
                                    {r.label}
                                </option>
                            ))}
                        </select>
                    );
                },
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'status',
                header: 'Status',
                cell: ({ row }) =>
                    row.original.active ? (
                        <span className="text-green-700">Active</span>
                    ) : (
                        <span className="text-[var(--color-ink-muted)]">Deactivated</span>
                    ),
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'two_factor',
                header: '2FA',
                cell: ({ row }) => (row.original.two_factor_enabled ? 'On' : DASH),
                enableSorting: false,
            }),
            columnHelper.accessor('created_at', {
                header: 'Added',
                cell: (info) => {
                    const v = info.getValue();
                    return v ? new Date(v).toLocaleDateString() : DASH;
                },
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => {
                    const u = row.original;
                    return (
                        <button
                            type="button"
                            disabled={u.is_self}
                            title={u.is_self ? 'You cannot change your own account here' : undefined}
                            onClick={() => {
                                setActive.reset();
                                setPendingState({ user: u, mode: u.active ? 'deactivate' : 'reactivate' });
                            }}
                            className={`rounded-md border px-2 py-1 text-xs disabled:opacity-40 ${
                                u.active ? 'text-red-700' : 'text-[var(--color-ink)]'
                            }`}
                        >
                            {u.active ? 'Deactivate' : 'Reactivate'}
                        </button>
                    );
                },
                enableSorting: false,
            }),
        ],
        // setActive.reset is stable; the rest are primitives or stable mutate functions.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [roles, changingId, changeRoleMutate],
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
            setSearch({ sort: first.id as UserSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    const meta = data?.meta;

    function confirmState() {
        if (!pendingState) return;
        setActive.mutate(
            { userId: pendingState.user.id, active: pendingState.mode === 'reactivate' },
            { onSuccess: () => setPendingState(null) },
        );
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Users</h1>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    <button
                        type="button"
                        onClick={() => setInviteOpen(true)}
                        className="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm text-white hover:opacity-90"
                    >
                        Invite user
                    </button>
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    placeholder="Search name or email..."
                    value={qInput}
                    onChange={(e) => setQInput(e.target.value)}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                <select
                    aria-label="Filter by role"
                    value={search.role ?? ''}
                    onChange={(e) => setSearch({ role: e.target.value, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All roles</option>
                    {roles.map((r) => (
                        <option key={r.name} value={r.name}>
                            {r.label}
                        </option>
                    ))}
                </select>
                <select
                    aria-label="Filter by status"
                    value={search.status ?? ''}
                    onChange={(e) => setSearch({ status: (e.target.value || undefined) as UserStatus | undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="deactivated">Deactivated</option>
                </select>
            </div>

            {changeRole.isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(changeRole.error, 'Could not change the role. Please try again.')}
                </p>
            )}

            {isError && <p className="text-sm text-red-600">Couldn't load users.</p>}

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
                            <tr key={row.id} className={row.original.active ? '' : 'bg-gray-50 text-gray-500'}>
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
                                    No users found.
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

            <PendingInvitations />

            <InviteUserDialog open={inviteOpen} onClose={() => setInviteOpen(false)} />

            <ConfirmUserStateDialog
                user={pendingState?.user ?? null}
                mode={pendingState?.mode ?? 'deactivate'}
                isPending={setActive.isPending}
                error={setActive.isError ? apiErrorMessage(setActive.error, 'Could not update the user. Please try again.') : null}
                onConfirm={confirmState}
                onClose={() => {
                    setActive.reset();
                    setPendingState(null);
                }}
            />
        </div>
    );
}
