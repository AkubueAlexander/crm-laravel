import { createRootRoute, createRoute, createRouter, Outlet } from '@tanstack/react-router';
import { RequireAuth } from '@/shared/components/RequireAuth';
import { AppShell } from '@/shared/components/AppShell';
import { LoginForm } from '@/features/auth/components/LoginForm';
import { DealsBoard } from '@/features/deals/components/Board';
import { ForecastDashboard } from '@/features/forecasting/components/Dashboard';

const rootRoute = createRootRoute({
    component: () => <Outlet />,
});

const dealsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/deals',
    component: DealsBoard,
});

const loginRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/login',
    component: () => (
        <div className="flex h-screen items-center justify-center bg-[var(--color-paper)]">
            <div className="flex flex-col gap-6 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-8">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Sign in</h1>
                <LoginForm />
            </div>
        </div>
    ),
});


const authenticatedLayoutRoute = createRoute({
    getParentRoute: () => rootRoute,
    id: 'authenticated',
    component: () => (
        <RequireAuth>
            <AppShell>
                <Outlet />
            </AppShell>
        </RequireAuth>
    ),
});

const dashboardRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/',
    component: () => (
        <div className="text-sm text-[var(--color-ink-muted)]">
            Phase 1 scaffold — feature routes (Deals, Contacts, Forecast, …) land in Phases 2–5.
        </div>
    ),
});

const forecastRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/forecast',
    component: ForecastDashboard,
});

const routeTree = rootRoute.addChildren([
    loginRoute,
    authenticatedLayoutRoute.addChildren([dashboardRoute, dealsRoute, forecastRoute]),
]);
export const router = createRouter({ routeTree });

declare module '@tanstack/react-router' {
    interface Register {
        router: typeof router;
    }
}
