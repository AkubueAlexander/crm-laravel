import { createRoute, notFound, useParams, type AnyRoute } from '@tanstack/react-router';
import { SegmentBuilderPage } from '@/features/marketing/components/SegmentBuilderPage';

function NewSegmentRoute() {
  return <SegmentBuilderPage />;
}

function EditSegmentRoute() {
  const { segmentId } = useParams({ strict: false }) as { segmentId?: number };
  if (segmentId === undefined) return null;
  return <SegmentBuilderPage segmentId={segmentId} />;
}

/**
 * Registers /marketing/segments/new and /marketing/segments/$segmentId under the same parent
 * as /marketing/segments. Static "new" beats the dynamic segment in TanStack Router matching.
 */
export function createSegmentBuilderRoutes<TParent extends AnyRoute>(getParentRoute: () => TParent) {
  const newRoute = createRoute({
    getParentRoute,
    path: '/marketing/segments/new',
    component: NewSegmentRoute,
  });

  const editRoute = createRoute({
    getParentRoute,
    path: '/marketing/segments/$segmentId',
    params: {
      parse: (raw: { segmentId: string }) => {
        const id = Number(raw.segmentId);
        if (!Number.isInteger(id) || id < 1) throw notFound();
        return { segmentId: id };
      },
      stringify: (params: { segmentId: number }) => ({ segmentId: String(params.segmentId) }),
    },
    component: EditSegmentRoute,
  });

  return [newRoute, editRoute] as const;
}