import { useContactImport } from '../api/useContactImport';
import { IMPORT_STATUS_LABELS, isImportTerminal } from '../schemas/contactImport';
import { ImportProgressBar } from './ContactImportDialog';

type ContactImportToastProps = {
    importId: number;
    onOpen: () => void;
    onDismiss: () => void;
};

// Shown while the dialog is closed so a long import stays visible. It reads the same
// cached query as the dialog, so it costs no extra request.
export function ContactImportToast({ importId, onOpen, onDismiss }: ContactImportToastProps) {
    const { data } = useContactImport(importId);

    if (!data) return null;

    const terminal = isImportTerminal(data.status);

    return (
        <div
            role="status"
            className="fixed bottom-4 right-4 z-40 w-72 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4 text-[var(--color-ink)] shadow-lg"
        >
            <div className="mb-2 flex items-center justify-between text-sm">
                <span className="font-medium">Contact import</span>
                <span className="text-[var(--color-ink-muted)]">{IMPORT_STATUS_LABELS[data.status]}</span>
            </div>

            <ImportProgressBar processed={data.processed_rows} total={data.total_rows} status={data.status} />

            <p className="mt-2 text-xs text-[var(--color-ink-muted)]">
                {data.created_rows} created, {data.duplicate_rows} duplicates, {data.invalid_rows} invalid
            </p>

            {data.status === 'failed' && data.error && <p className="mt-1 text-xs text-red-600">{data.error}</p>}

            <div className="mt-3 flex items-center gap-3 text-sm">
                <button type="button" onClick={onOpen} className="font-medium text-indigo-600 hover:underline">
                    {terminal ? 'View results' : 'View progress'}
                </button>
                <button
                    type="button"
                    onClick={onDismiss}
                    className="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                >
                    Dismiss
                </button>
            </div>
        </div>
    );
}
