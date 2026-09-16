import { useCurrentUser } from '@/features/auth/api/useCurrentUser';

/**
 * 2.3 (non-negotiable): this hook only ever controls what's SHOWN, never what's
 * ALLOWED. Every permission check here is duplicated server-side in the
 * corresponding Action/policy — "a hidden button is not a security boundary."
 */
export function usePermission(permission: string): boolean {
    const { data } = useCurrentUser();
    return data?.permissions.includes(permission) ?? false;
}
