import { lazy } from 'react';
import { createRoute, lazyRouteComponent, notFound, useParams, type AnyRoute } from '@tanstack/react-router';
import { validateAutomationsSearch } from '@/features/marketing/schemas/automation';

// 6.3b: pages are their own chunks; the route's pending fallback covers the load.
const AutomationsPage = lazyRouteComponent(() => import('@/features/marketing/components/AutomationsPage'), 'AutomationsPage');
const AutomationBuilderPage = lazy(() =>
    import('@/features/marketing/components/AutomationBuilderPage').then((m) => ({ default: m.AutomationBuilderPage })),
);

function NewAutomationRoute() {
    return <AutomationBuilderPage />;
}

function EditAutomationRoute() {
    const { automationId } = useParams({ strict: false }) as { automationId?: number };
    if (automationId === undefined) return null;
    return <AutomationBuilderPage automationId={automationId} />;
}

/**
 * Registers /marketing/automations (list, URL-persisted filters), /marketing/automations/new and
 * /marketing/automations/$automationId under the same parent as the other marketing routes.
 * Static "new" beats the dynamic segment in TanStack Router matching.
 */
export function createAutomationRoutes<TParent extends AnyRoute>(getParentRoute: () => TParent) {
    const listRoute = createRoute({
        getParentRoute,
        path: '/marketing/automations',
        validateSearch: validateAutomationsSearch,
        component: AutomationsPage,
    });

    const newRoute = createRoute({
        getParentRoute,
        path: '/marketing/automations/new',
        component: NewAutomationRoute,
    });

    const editRoute = createRoute({
        getParentRoute,
        path: '/marketing/automations/$automationId',
        params: {
            parse: (raw: { automationId: string }) => {
                const id = Number(raw.automationId);
                if (!Number.isInteger(id) || id < 1) throw notFound();
                return { automationId: id };
            },
            stringify: (params: { automationId: number }) => ({ automationId: String(params.automationId) }),
        },
        component: EditAutomationRoute,
    });

    return [listRoute, newRoute, editRoute] as const;
}
