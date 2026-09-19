import { useCurrentUser } from '@/features/auth/api/useCurrentUser';


export function usePermission(permission: string): boolean {
    const { data } = useCurrentUser();
    return data?.permissions.includes(permission) ?? false;
}
