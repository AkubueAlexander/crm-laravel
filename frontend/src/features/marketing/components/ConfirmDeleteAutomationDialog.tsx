import { useEffect } from 'react';
import type { Automation } from '../schemas/automation';

type Props = {
    automation: Automation | null;
    isPending: boolean;
    error: string | null;
    onConfirm: () => void;
    onClose: () => void;
};

export function ConfirmDeleteAutomationDialog({ automation, isPending, error, onConfirm, onClose }: Props) {
    useEffect(() => {
        if (!automation) return;
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !isPending) onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [automation, isPending, onClose]);

    if (!automation) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="delete-automation-title"
                className="w-full max-w-md rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg"
            >
                <h2 id="delete-automation-title" className="text-base font-medium text-[var(--color-ink)]">
                    Delete automation?
                </h2>
                <p className="mt-2 text-sm text-[var(--color-ink-muted)]">
                    &ldquo;{automation.name}&rdquo; and its steps will be permanently deleted. Contacts, segments and templates are not
                    affected. This cannot be undone.
                </p>

                {error && (
                    <p role="alert" className="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {error}
                    </p>
                )}

                <div className="mt-5 flex justify-end gap-2">
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
                        className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {isPending ? 'Deleting...' : 'Delete'}
                    </button>
                </div>
            </div>
        </div>
    );
}