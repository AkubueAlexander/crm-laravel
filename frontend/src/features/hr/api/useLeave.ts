import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { employeesPageSchema } from '../schemas/hr';
import { leaveRequestsPageSchema, type LeaveRequestsPage, type LeaveStatus } from '../schemas/leave';
import { hrRootKey } from './useHr';

// All leave queries live under this key (tenant-namespaced, 1.2); mutations patch and invalidate it.
export function leaveRootKey(tenantId: unknown) {
    return [...hrRootKey(tenantId), 'leave'] as const;
}

export function useLeaveRequests({ page, status, sort, enabled }: { page: number; status?: LeaveStatus; sort: 'start_date' | '-start_date'; enabled: boolean }) {
    const { tenant } = useTenant();
    return useQuery<LeaveRequestsPage>({
        queryKey: [...leaveRootKey(tenant?.id), { page, status, sort }],
        enabled: enabled && !!tenant,
        placeholderData: keepPreviousData,
        queryFn: async () => {
            const params: Record<string, string | number> = { page, per_page: 25, sort };
            if (status) params.status = status;
            const { data } = await apiClient.get('/hr/leave-requests', { params });
            return leaveRequestsPageSchema.parse(data);
        },
    });
}

// Active employees (id + name) for the create form of hr.manage users (first 100).
export function useEmployeeOptions(enabled: boolean) {
    const { tenant } = useTenant();
    return useQuery<{ id: number; name: string }[]>({
        queryKey: [...hrRootKey(tenant?.id), 'employee-options'],
        enabled: enabled && !!tenant,
        queryFn: async () => {
            const { data } = await apiClient.get('/hr/employees', { params: { active: '1', per_page: 100 } });
            return employeesPageSchema.parse(data).data.map((e) => ({ id: e.id, name: e.name }));
        },
    });
}
