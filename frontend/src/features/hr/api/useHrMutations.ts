import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    employeeSchema,
    hrUnitSchema,
    HR_UNIT_LABELS,
    type Employee,
    type EmployeeFormValues,
    type HrUnit,
    type HrUnitKind,
    type UnitFormValues,
} from '../schemas/hr';
import { hrRootKey } from './useHr';

// Field messages from a 422 ({ errors: { team_id: [..] } }), first message per field. Empty for any other error.
export function hrFieldErrors(err: unknown): Record<string, string> {
    if (!isApiError(err) || err.response?.status !== 422) return {};
    const errors = (err.response?.data as { errors?: Record<string, string[]> } | undefined)?.errors ?? {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        if (Array.isArray(messages) && typeof messages[0] === 'string') out[field] = messages[0];
    }
    return out;
}

function useInvalidateHr() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    return () => void queryClient.invalidateQueries({ queryKey: hrRootKey(tenant?.id) });
}

const nul = (v: string): string | null => {
    const t = v.trim();
    return t === '' ? null : t;
};
const num = (v: string): number | null => (v === '' ? null : Number(v));

export type SaveEmployeeVars = { employeeId?: number; values: EmployeeFormValues };

// NOT optimistic: the server validates ownership of the linked ids and uniqueness. An edit replaces the profile.
export function useSaveEmployee() {
    const invalidate = useInvalidateHr();
    return useMutation({
        mutationFn: async ({ employeeId, values }: SaveEmployeeVars): Promise<Employee> => {
            const body = {
                name: values.name.trim(),
                email: nul(values.email),
                phone: nul(values.phone),
                job_title: nul(values.job_title),
                hire_date: nul(values.hire_date),
                user_id: num(values.user_id),
                team_id: num(values.team_id),
                territory_id: num(values.territory_id),
                is_active: values.is_active,
            };
            const { data } = employeeId ? await apiClient.put(`/hr/employees/${employeeId}`, body) : await apiClient.post('/hr/employees', body);
            return employeeSchema.parse(data);
        },
        onSuccess: invalidate,
    });
}

export type SaveHrUnitVars = { kind: HrUnitKind; unitId?: number; values: UnitFormValues };

// NOT optimistic: names are unique per tenant and kind, decided by the server.
export function useSaveHrUnit() {
    const invalidate = useInvalidateHr();
    return useMutation({
        mutationFn: async ({ kind, unitId, values }: SaveHrUnitVars): Promise<HrUnit> => {
            const path = `/hr/${HR_UNIT_LABELS[kind].path}`;
            const body = { name: values.name.trim(), description: nul(values.description), is_active: values.is_active };
            const { data } = unitId ? await apiClient.put(`${path}/${unitId}`, body) : await apiClient.post(path, body);
            return hrUnitSchema.parse(data);
        },
        onSuccess: invalidate,
    });
}
