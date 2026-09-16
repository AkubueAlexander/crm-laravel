import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import type { Tenant } from '@/features/auth/schemas/auth';


export function useTenant(): { tenant: Tenant | undefined; isLoading: boolean } {
    const { data, isLoading } = useCurrentUser();
    return { tenant: data?.tenant, isLoading };
}


export function useTenantQueryKey(rest: readonly unknown[]): readonly unknown[] {
    const { tenant } = useTenant();
    return [rest[0], tenant?.id, ...rest.slice(1)];
}
