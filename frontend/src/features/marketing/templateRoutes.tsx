import { lazy } from 'react';
import { createRoute, lazyRouteComponent, notFound, useParams, type AnyRoute } from '@tanstack/react-router';
import { validateTemplatesSearch } from '@/features/marketing/schemas/template';

// 6.3b: pages are their own chunks; the route's pending fallback covers the load.
const TemplatesPage = lazyRouteComponent(() => import('@/features/marketing/components/TemplatesPage'), 'TemplatesPage');
const TemplateEditorPage = lazy(() =>
  import('@/features/marketing/components/TemplateEditorPage').then((m) => ({ default: m.TemplateEditorPage })),
);

function NewTemplateRoute() {
  return <TemplateEditorPage />;
}

function EditTemplateRoute() {
  const { templateId } = useParams({ strict: false }) as { templateId?: number };
  if (templateId === undefined) return null;
  return <TemplateEditorPage templateId={templateId} />;
}

/**
 * Registers the email template routes under the same parent as the other marketing routes.
 * Static "new" beats the dynamic segment in TanStack Router matching.
 */
export function createTemplateRoutes<TParent extends AnyRoute>(getParentRoute: () => TParent) {
  const listRoute = createRoute({
    getParentRoute,
    path: '/marketing/templates',
    validateSearch: validateTemplatesSearch,
    component: TemplatesPage,
  });

  const newRoute = createRoute({
    getParentRoute,
    path: '/marketing/templates/new',
    component: NewTemplateRoute,
  });

  const editRoute = createRoute({
    getParentRoute,
    path: '/marketing/templates/$templateId',
    params: {
      parse: (raw: { templateId: string }) => {
        const id = Number(raw.templateId);
        if (!Number.isInteger(id) || id < 1) throw notFound();
        return { templateId: id };
      },
      stringify: (params: { templateId: number }) => ({ templateId: String(params.templateId) }),
    },
    component: EditTemplateRoute,
  });

  return [listRoute, newRoute, editRoute] as const;
}
