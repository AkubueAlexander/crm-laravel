import { useEffect } from 'react';
import { useForm, type Path } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useHrUnitOptions, useUserOptions } from '../api/useHr';
import { hrFieldErrors, useSaveEmployee } from '../api/useHrMutations';
import { employeeFormSchema, employeeToForm, type Employee, type EmployeeFormValues } from '../schemas/hr';

type Option = { id: number; name: string };

// Keeps a currently held inactive unit selectable so saving does not silently clear it.
function withCurrent(options: Option[], current: Option | null | undefined): Option[] {
    if (current && !options.some((o) => o.id === current.id)) return [...options, { id: current.id, name: `${current.name} (inactive)` }];
    return options;
}

const INPUT = 'w-full rounded-md border px-3 py-2 text-sm';

// 13.1c: create / edit an employee. RHF + Zod (8.3); the server is the boundary (14.2).
export function EmployeeFormModal({ open, employee, onClose }: { open: boolean; employee: Employee | null; onClose: () => void }) {
    const canLinkUsers = usePermission('accounts.view');
    const users = useUserOptions(open && canLinkUsers);
    const teams = useHrUnitOptions('team', open);
    const territories = useHrUnitOptions('territory', open);
    const save = useSaveEmployee();

    const form = useForm<EmployeeFormValues>({ resolver: zodResolver(employeeFormSchema), defaultValues: employeeToForm(employee) });

    useEffect(() => {
        if (open) {
            form.reset(employeeToForm(employee));
            save.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, employee?.id]);

    const userOptions = withCurrent(
        (users.data ?? []).map((u) => ({ id: u.id, name: u.name })),
        employee?.user_id ? { id: employee.user_id, name: employee.user_name ?? `User ${employee.user_id}` } : null,
    );
    const teamOptions = withCurrent((teams.data ?? []).map((t) => ({ id: t.id, name: t.name })), employee?.team);
    const territoryOptions = withCurrent((territories.data ?? []).map((t) => ({ id: t.id, name: t.name })), employee?.territory);

    const { errors } = form.formState;

    const onSubmit = form.handleSubmit((values) => {
        save.mutate(
            { employeeId: employee?.id, values },
            {
                onSuccess: () => onClose(),
                onError: (err) => {
                    let mapped = false;
                    for (const [field, message] of Object.entries(hrFieldErrors(err))) {
                        if (field in values) {
                            form.setError(field as Path<EmployeeFormValues>, { message });
                            mapped = true;
                        }
                    }
                    if (!mapped) form.setError('root', { message: apiErrorMessage(err, "Couldn't save the employee.") });
                },
            },
        );
    });

    return (
        <Modal open={open} onClose={onClose} title={employee ? 'Edit employee' : 'New employee'}>
            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-3">
                <label className="flex flex-col gap-1 text-sm">
                    Name
                    <input {...form.register('name')} className={INPUT} />
                    {errors.name && <span className="text-xs text-red-700">{errors.name.message}</span>}
                </label>
                <div className="grid grid-cols-2 gap-3">
                    <label className="flex flex-col gap-1 text-sm">
                        Email
                        <input type="email" {...form.register('email')} className={INPUT} />
                        {errors.email && <span className="text-xs text-red-700">{errors.email.message}</span>}
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Phone
                        <input {...form.register('phone')} className={INPUT} />
                        {errors.phone && <span className="text-xs text-red-700">{errors.phone.message}</span>}
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Job title
                        <input {...form.register('job_title')} className={INPUT} />
                        {errors.job_title && <span className="text-xs text-red-700">{errors.job_title.message}</span>}
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Hire date
                        <input type="date" {...form.register('hire_date')} className={INPUT} />
                        {errors.hire_date && <span className="text-xs text-red-700">{errors.hire_date.message}</span>}
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Team
                        <select {...form.register('team_id')} className={INPUT}>
                            <option value="">No team</option>
                            {teamOptions.map((o) => (
                                <option key={o.id} value={String(o.id)}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                        {errors.team_id && <span className="text-xs text-red-700">{errors.team_id.message}</span>}
                    </label>
                    <label className="flex flex-col gap-1 text-sm">
                        Territory
                        <select {...form.register('territory_id')} className={INPUT}>
                            <option value="">No territory</option>
                            {territoryOptions.map((o) => (
                                <option key={o.id} value={String(o.id)}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                        {errors.territory_id && <span className="text-xs text-red-700">{errors.territory_id.message}</span>}
                    </label>
                </div>
                {canLinkUsers && (
                    <label className="flex flex-col gap-1 text-sm">
                        Linked user (login)
                        <select {...form.register('user_id')} className={INPUT}>
                            <option value="">Not linked</option>
                            {userOptions.map((o) => (
                                <option key={o.id} value={String(o.id)}>
                                    {o.name}
                                </option>
                            ))}
                        </select>
                        {errors.user_id && <span className="text-xs text-red-700">{errors.user_id.message}</span>}
                    </label>
                )}
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
