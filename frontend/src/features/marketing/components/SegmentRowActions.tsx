import { Link } from '@tanstack/react-router';
import { usePermission } from '@/features/marketing/api/segmentDeps';

/** "New segment" button for the list header. Rendered only for marketing.manage. */
export function NewSegmentButton() {
  const canManage = usePermission('marketing.manage');
  if (!canManage) return null;
  return (
    <Link
      to="/marketing/segments/new"
      className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm font-medium text-[var(--color-paper)]"
    >
      New segment
    </Link>
  );
}

/** Per-row link: "Edit" for managers, "View" (read-only builder) for everyone else. */
export function EditSegmentLink({ id }: { id: number }) {
  const canManage = usePermission('marketing.manage');
  return (
    <Link
      to="/marketing/segments/$segmentId"
      params={{ segmentId: id }}
      className="text-sm underline"
    >
      {canManage ? 'Edit' : 'View'}
    </Link>
  );
}