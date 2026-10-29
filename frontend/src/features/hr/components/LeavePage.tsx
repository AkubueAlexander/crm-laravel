import { useEffect, useMemo, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useEmployees } from '../api/useHr';
import { useLeaveRequests } from '../api/useLeave';
import { leaveErrorMessage, useTransitionLeaveRequest } from '../api/useLeaveMutations';
import {
    LEAVE_STATUSES,
    LEAVE_STATUS_LABELS,
    LEAVE_TYPE_LABELS,
    rejectFormSchema,
    validateLeaveSearch,
    type LeaveRequest,
    type LeaveSearch,
    type LeaveStatus,
    type RejectFormValues,
} from '../schemas/leave';
import { LeaveRequestModal } from './LeaveRequestModal';

const columnHelper = createColumnHelper<LeaveRequest>();
const EMPTY: LeaveRequest[] = [];

const STATUS_STYLE: Record<LeaveStatus, string> = {
    pending: 'bg-amber-100 text-amber-800',
    approved: 'bg-green-100 text-green-800',
    rejected: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-700',
};

function StatusBadge({ status }: { status: LeaveStatus }) {
    return <span className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${STATUS_STYLE[status]}`}>{LEAVE_STATUS_LABELS[status]}</span>;
}

function RejectModal({ request, error, pending, onClose, onSubmit }: { request: LeaveRequest | null; error: string | null; pending: boolean; onClose: () => void; onSubmit: (note: string) => void }) {
    const form = useForm<RejectFormValues>({ resolver: zodResolver(rejectFormSchema), defaultValues: { note: '' } });
    useEffect(() => {
        if (request) form.reset({ note: '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [request?.id]);
    const submit = form.handleSubmit((v) => onSubmit(v.note.trim()));
    return (
        <Modal open={request !== null} onClose={onClose} title="Reject leave request">
            <form onSubmit={submit} noValidate className="flex flex-col gap-3">
                <p className="text-sm text-[var(--color-ink-muted)]">
                    {request?.employee?.name ?? 'Employee'}: {request?.start_date} to {request?.end_date}
                </p>
                <label className="flex flex-col gap-1 text-sm">
                    Reason for rejecting
                    <textarea rows={3} {...form.register('note')} className="w-full rounded-md border px-3 py-2 text-sm" />
                    {form.formState.errors.note && <span className="text-xs text-red-700">{form.formState.errors.note.message}</span>}
                </label>
                {error && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {error}
                    </p>
                )}
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-md border px-3 py-2 text-sm">
                        Back
                    </button>
                    <button type="submit" disabled={pending} className="rounded-md bg-red-700 px-3 py-2 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {pending ? 'Rejecting...' : 'Reject'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}

// 13.2b: leave requests. Status, sort and page live in the URL (8.7).
export function LeavePage() {
    const allowed = usePermission('hr.view');
    const canManage = usePermission('hr.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateLeaveSearch(raw);
    const page = search.page ?? 1;
    const sort = search.sort ?? '-start_date';
    const [editing, setEditing] = useState<LeaveRequest | 'new' | null>(null);
    const [rejecting, setRejecting] = useState<LeaveRequest | null>(null);
    const [actionError, setActionError] = useState<string | null>(null);

    function setSearch(patch: Partial<LeaveSearch>) {
        void navigate({ to: '/hr/leave', search: validateLeaveSearch({ ...search, ...patch }), replace: true });
    }

    const { data, isPending, isFetching, isError, error } = useLeaveRequests({ page, status: search.status, sort, enabled: allowed });
    // Non-managers are fixed to their own employee record: prefer the one on their requests, else the first employee returned.
    const ownEmployees = useEmployees({ page: 1, status: 'active', enabled: allowed && !canManage });
    const ownEmployee = useMemo(() => {
        if (canManage) return null;
        const fromRow = data?.data.find((r) => r.employee)?.employee;
        if (fromRow) return fromRow;
        const first = ownEmployees.data?.data[0];
        return first ? { id: first.id, name: first.name } : null;
    }, [canManage, data, ownEmployees.data]);

    const transition = useTransitionLeaveRequest();

    function run(r: LeaveRequest, to: LeaveStatus, note?: string) {
        setActionError(null);
        transition.mutate(
            { id: r.id, toStatus: to, lockVersion: r.lock_version, note },
            {
                onSuccess: () => setRejecting(null),
                onError: (err) => setActionError(leaveErrorMessage(err, "Couldn't update the leave request.")),
            },
        );
    }

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const busy = transition.isPending;

    const columns = useMemo(
        () => [
            ...(canManage ? [columnHelper.display({ id: 'employee', header: 'Employee', cell: ({ row }) => <span className="font-medium">{row.original.employee?.name ?? ''}</span> })] : []),
            columnHelper.display({ id: 'type', header: 'Type', cell: ({ row }) => LEAVE_TYPE_LABELS[row.original.type] }),
            columnHelper.display({
                id: 'dates',
                header: () => (
                    <button type="button" onClick={() => setSearch({ sort: sort === 'start_date' ? undefined : 'start_date', page: undefined })} className="font-medium underline">
                        Dates {sort === 'start_date' ? '(oldest first)' : '(newest first)'}
                    </button>
                ),
                cell: ({ row }) => `${row.original.start_date} to ${row.original.end_date}`,
            }),
            columnHelper.accessor('days', { header: 'Days', cell: (info) => info.getValue() }),
            columnHelper.accessor('status', { header: 'Status', cell: (info) => <StatusBadge status={info.getValue()} /> }),
            columnHelper.display({
                id: 'notes',
                header: 'Notes',
                cell: ({ row }) => (
                    <div className="max-w-xs text-xs text-[var(--color-ink-muted)]">
                        {row.original.reason && <div>{row.original.reason}</div>}
                        {row.original.decision_note && <div className="italic">Decision: {row.original.decision_note}</div>}
                    </div>
                ),
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => {
                    const r = row.original;
                    const btn = 'rounded-md border px-2 py-1 text-xs disabled:opacity-40';
                    const pendingRow = r.status === 'pending';
                    const canCancel = pendingRow || (canManage && r.status === 'approved');
                    return (
                        <div className="flex flex-wrap justify-end gap-1">
                            {canManage && pendingRow && (
                                <>
                                    <button type="button" disabled={busy} onClick={() => run(r, 'approved')} className={btn}>
                                        Approve
                                    </button>
                                    <button type="button" disabled={busy} onClick={() => { setActionError(null); setRejecting(r); }} className={btn}>
                                        Reject
                                    </button>
                                </>
                            )}
                            {pendingRow && (
                                <button type="button" disabled={busy} onClick={() => setEditing(r)} className={btn}>
                                    Edit
                                </button>
                            )}
                            {canCancel && (
                                <button
                                    type="button"
                                    disabled={busy}
                                    onClick={() => {
                                        if (window.confirm('Cancel this leave request?')) run(r, 'cancelled');
                                    }}
                                    className={btn}
                                >
                                    Cancel
                                </button>
                            )}
                        </div>
                    );
                },
            }),
        ],
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [canManage, busy, sort, search.status, search.page],
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

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <div className="flex items-center gap-4">
                    <h1 className="text-lg font-medium text-[var(--color-ink)]">Leave requests</h1>
                    <Link to="/hr" className="text-sm underline">
                        Employees
                    </Link>
                </div>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    <button type="button" onClick={() => setEditing('new')} className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90">
                        New request
                    </button>
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <button type="button" onClick={() => setSearch({ status: undefined, page: undefined })} className={`rounded-md border px-3 py-1.5 text-sm ${!search.status ? 'bg-[var(--color-ink)] text-white' : ''}`}>
                    All
                </button>
                {LEAVE_STATUSES.map((s) => (
                    <button key={s} type="button" onClick={() => setSearch({ status: s, page: undefined })} className={`rounded-md border px-3 py-1.5 text-sm ${search.status === s ? 'bg-[var(--color-ink)] text-white' : ''}`}>
                        {LEAVE_STATUS_LABELS[s]}
                    </button>
                ))}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load leave requests.")}
                </p>
            )}
            {actionError && !rejecting && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {actionError}
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
                                    {search.status ? 'No leave requests with this status.' : 'No leave requests yet.'}
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

            <LeaveRequestModal open={editing !== null} request={editing === 'new' ? null : editing} ownEmployee={ownEmployee} onClose={() => setEditing(null)} />
            <RejectModal request={rejecting} error={actionError} pending={busy} onClose={() => setRejecting(null)} onSubmit={(note) => rejecting && run(rejecting, 'rejected', note)} />
        </div>
    );
}
