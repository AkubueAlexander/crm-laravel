import { Link } from '@tanstack/react-router';
import { usePermission } from '@/features/marketing/api/segmentDeps';

/** "New template" button for the list header. Rendered only for marketing.manage. */
export function NewTemplateButton() {
  const canManage = usePermission('marketing.manage');
  if (!canManage) return null;
  return (
    <Link
      to="/marketing/templates/new"
      className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm font-medium text-[var(--color-paper)]"
    >
      New template
    </Link>
  );
}

/** Per-row link: "Edit" for managers, "View" (read-only editor) for everyone else. */
export function EditTemplateLink({ id }: { id: number }) {
  const canManage = usePermission('marketing.manage');
  return (
    <Link
      to="/marketing/templates/$templateId"
      params={{ templateId: id }}
      className="text-sm underline"
    >
      {canManage ? 'Edit' : 'View'}
    </Link>
  );
}