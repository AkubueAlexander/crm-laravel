import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    employeesPageSchema,
    hrUnitsPageSchema,
    HR_UNIT_LABELS,
    userOptionsSchema,
    type EmployeesPage,
    type HrUnit,
    type HrUnitKind,
    type HrUnitsPage,
    type UserOption,
} from '../schemas/hr';

// Everything under this key is tenant-namespaced (1.2); mutations invalidate it.
export function hrRootKey(tenantId: unknown) {
    return ['hr', tenantId] as const;
}

type Status = 'active' | 'inactive' | undefined;

function activeParam(status: Status): string | undefined {
    return status === 'active' ? '1' : status === 'inactive' ? '0' : undefined;
}

export function useEmployees({
    page,
    q,
    teamId,
    territoryId,
    status,
    enabled,
}: {
    page: number;
    q?: string;
    teamId?: number;
    territoryId?: number;
    status?: Status;
    enabled: boolean;
}) {
    const { tenant } = useTenant();
    return useQuery<EmployeesPage>({
        queryKey: [...hrRootKey(tenant?.id), 'employees', { page, q, teamId, territoryId, status }],
        enabled: enabled && !!tenant,
        placeholderData: keepPreviousData,
        queryFn: async () => {
            const params: Record<string, string | number> = { page, per_page: 25 };
            if (q) params.q = q;
            if (teamId) params.team_id = teamId;
            if (territoryId) params.territory_id = territoryId;
            const active = activeParam(status);
            if (active) params.active = active;
            const { data } = await apiClient.get('/hr/employees', { params });
            return employeesPageSchema.parse(data);
        },
    });
}

export function useHrUnits({ kind, page, q, status, enabled }: { kind: HrUnitKind; page: number; q?: string; status?: Status; enabled: boolean }) {
    const { tenant } = useTenant();
    return useQuery<HrUnitsPage>({
        queryKey: [...hrRootKey(tenant?.id), 'units', kind, { page, q, status }],
        enabled: enabled && !!tenant,
        placeholderData: keepPreviousData,
        queryFn: async () => {
            const params: Record<string, string | number> = { page, per_page: 25 };
            if (q) params.q = q;
            const active = activeParam(status);
            if (active) params.active = active;
            const { data } = await apiClient.get(`/hr/${HR_UNIT_LABELS[kind].path}`, { params });
            return hrUnitsPageSchema.parse(data);
        },
    });
}

// Active units of a kind, for selects and filters (first 100 by name).
export function useHrUnitOptions(kind: HrUnitKind, enabled: boolean) {
    const { tenant } = useTenant();
    return useQuery<HrUnit[]>({
        queryKey: [...hrRootKey(tenant?.id), 'unit-options', kind],
        enabled: enabled && !!tenant,
        queryFn: async () => {
            const { data } = await apiClient.get(`/hr/${HR_UNIT_LABELS[kind].path}`, { params: { active: '1', per_page: 100, sort: 'name' } });
            return hrUnitsPageSchema.parse(data).data;
        },
    });
}

// Login users of the tenant (id + name), for linking an employee to a user.
export function useUserOptions(enabled: boolean) {
    const { tenant } = useTenant();
    return useQuery<UserOption[]>({
        queryKey: [...hrRootKey(tenant?.id), 'user-options'],
        enabled: enabled && !!tenant,
        queryFn: async () => {
            const { data } = await apiClient.get('/users/options');
            return userOptionsSchema.parse(data);
        },
    });
}
