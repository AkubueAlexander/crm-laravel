import { createRoute, notFound, useParams, type AnyRoute } from '@tanstack/react-router';
import { TemplateEditorPage } from '@/features/marketing/components/TemplateEditorPage';
import { TemplatesPage } from '@/features/marketing/components/TemplatesPage';
import { validateTemplatesSearch } from '@/features/marketing/schemas/template';

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