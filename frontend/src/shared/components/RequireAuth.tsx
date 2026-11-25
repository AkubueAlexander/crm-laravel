import { type ReactNode } from 'react';
import { Navigate } from '@tanstack/react-router';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';


export function RequireAuth({ children }: { children: ReactNode }) {
    const { isPending, isError } = useCurrentUser();

    if (isPending) {
        return <div className="flex h-screen items-center justify-center text-sm text-neutral-500">Loading…</div>;
    }

    if (isError) {
        return <Navigate to="/login" />;
    }

    return <>{children}</>;
}
