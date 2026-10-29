import { useEffect } from 'react';
import { useForm, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { useEmployeeOptions } from '../api/useLeave';
import { leaveErrorMessage, useSaveLeaveRequest } from '../api/useLeaveMutations';
import { hrFieldErrors } from '../api/useHrMutations';
import { LEAVE_TYPES, LEAVE_TYPE_LABELS, leaveFormSchema, leaveToForm, type LeaveFormValues, type LeaveRequest } from '../schemas/leave';

const INPUT = 'w-full rounded-md border px-3 py-2 text-sm';

// 13.2b: create / edit a leave request. RHF + Zod (8.3); the server is the boundary (14.2).
// hr.manage users pick any active employee; everyone else is fixed to their own employee record.
export function LeaveRequestModal({
    open,
    request,
    ownEmployee,
    onClose,
}: {
    open: boolean;
    request: LeaveRequest | null;
    ownEmployee: { id: number; name: string } | null;
    onClose: () => void;
}) {
    const canManage = usePermission('hr.manage');
    const employees = useEmployeeOptions(open && canManage && !request);
    const save = useSaveLeaveRequest();
    const form = useForm<LeaveFormValues>({ resolver: zodResolver(leaveFormSchema), defaultValues: leaveToForm(request, ownEmployee?.id) });

    useEffect(() => {
        if (open) {
            form.reset(leaveToForm(request, canManage ? null : ownEmployee?.id));
            save.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, request?.id]);

    const { errors } = form.formState;
    const noEmployee = !request && !canManage && !ownEmployee;

    const onSubmit = form.handleSubmit((values) => {
        save.mutate(
            { requestId: request?.id, lockVersion: request?.lock_version, values },
            {
                onSuccess: () => onClose(),
                onError: (err) => {
                    let mapped = false;
                    for (const [field, message] of Object.entries(hrFieldErrors(err))) {
                        if (field in values) {
                            form.setError(field as Path<LeaveFormValues>, { message });
                            mapped = true;
                        }
                    }
                    if (!mapped) form.setError('root', { message: leaveErrorMessage(err, "Couldn't save the leave request.") });
                },
            },
        );
    });

    return (
        <Modal open={open} onClose={onClose} title={request ? 'Edit leave request' : 'New leave request'}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                {noEmployee && (
                    <p role="alert" className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                        Your login is not linked to an employee record. Ask an HR manager to link it.
                    </p>
                )}
                {canManage && !request ? (
                    <label className="flex flex-col gap-1 text-sm">
                        Employee
                        <select {...form.register('employee_id')} className={INPUT}>
                            <option value="">Select an employee</option>
                            {(employees.data ?? []).map((e) => (
                                <option key={e.id} value={String(e.id)}>
                                    {e.name}
                                </option>
                            ))}
                        </select>
                        {errors.employee_id && <span className="text-xs text-red-700">{errors.employee_id.message}</span>}
                    </label>
                ) : (
                    <div className="text-sm">
                        <span className="text-[var(--color-ink-muted)]">Employee: </span>
                        <span className="font-medium">{request?.employee?.name ?? ownEmployee?.name ?? ''}</span>
                        <input type="hidden" {...form.register('employee_id')} />
                    </div>
                )}
                <div className="grid grid-cols-3 gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        Type
                        <select {...form.register('type')} className={INPUT}>
                            {LEAVE_TYPES.map((t) => (
                                <option key={t} value={t}>
                                    {LEAVE_TYPE_LABELS[t]}
                                </option>
                            ))}
                        </select>
                        {errors.type && <span className="text-xs text-red-700">{errors.type.message}</span>}
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Start date
                        <input type="date" {...form.register('start_date')} className={INPUT} />
                        {errors.start_date && <span className="text-xs text-red-700">{errors.start_date.message}</span>}
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        End date
                        <input type="date" {...form.register('end_date')} className={INPUT} />
                        {errors.end_date && <span className="text-xs text-red-700">{errors.end_date.message}</span>}
                    </label>
                </div>
                <label className="flex flex-col gap-1 text-sm">
                    Reason (optional)
                    <textarea rows={3} {...form.register('reason')} className={INPUT} />
                    {errors.reason && <span className="text-xs text-red-700">{errors.reason.message}</span>}
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
                    <button type="submit" disabled={save.isPending || noEmployee} className="rounded-md bg-[var(--color-ink)] px-3 py-2 text-sm text-white hover:opacity-90 disabled:opacity-50">
                        {save.isPending ? 'Saving...' : 'Save'}
                    </button>
                </div>
            </form>
        </Modal>
    );
}
