import { useEffect } from 'react';
import type { EmailTemplate } from '@/features/marketing/schemas/template';

type Props = {
  template: EmailTemplate | null;
  isPending: boolean;
  error: string | null;
  onConfirm: () => void;
  onClose: () => void;
};

export function ConfirmDeleteTemplateDialog({ template, isPending, error, onConfirm, onClose }: Props) {
  useEffect(() => {
    if (!template) return;
    const onKey = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !isPending) onClose();
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [template, isPending, onClose]);

  if (!template) return null;

  return (
    <div
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
      role="presentation"
      onClick={() => {
        if (!isPending) onClose();
      }}
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-labelledby="delete-template-title"
        className="w-full max-w-md space-y-4 rounded-lg border border-[var(--color-border)] bg-[var(--color-paper)] p-5 shadow-lg"
        onClick={(event) => event.stopPropagation()}
      >
        <h2 id="delete-template-title" className="text-base font-medium text-[var(--color-ink)]">
          Delete template?
        </h2>
        <p className="text-sm text-[var(--color-ink-muted)]">
          &quot;{template.name}&quot; will be permanently deleted. This cannot be undone.
        </p>
        {error ? (
          <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
            {error}
          </p>
        ) : null}
        <div className="flex justify-end gap-2">
          <button
            type="button"
            onClick={onClose}
            disabled={isPending}
            className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
          >
            Cancel
          </button>
          <button
            type="button"
            onClick={onConfirm}
            disabled={isPending}
            className="rounded-md bg-red-700 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50"
          >
            {isPending ? 'Deleting...' : 'Delete'}
          </button>
        </div>
      </div>
    </div>
  );
}