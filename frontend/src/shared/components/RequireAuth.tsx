import { type ReactNode } from 'react';
import { Navigate } from '@tanstack/react-router';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';

/**
 * 2.2: reads useCurrentUser's own pending/error/success status rather than a
 * bespoke auth-state machine — TanStack Query's status enum already models
 * exactly the states a route guard needs.
 */
export function RequireAuth({ children }: { children: ReactNode }) {
    const { status } = useCurrentUser();

    if (status === 'pending') {
        return <div className="flex h-screen items-center justify-center text-sm text-neutral-500">Loading…</div>;
    }

    if (status === 'error') {
        return <Navigate to="/login" />;
    }

    return <>{children}</>;
}
