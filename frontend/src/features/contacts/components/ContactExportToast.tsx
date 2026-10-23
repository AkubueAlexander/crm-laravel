import { exportErrorMessage, useContactExport, useDownloadContactExport } from '../api/useContactExport';
import { EXPORT_FORMAT_LABELS, EXPORT_STATUS_LABELS } from '../schemas/contactExport';

type ContactExportToastProps = {
    exportId: number;
    // Tailwind bottom offset, so the toast stacks above the import toast when both are showing.
    offsetClassName?: string;
    onDismiss: () => void;
};

// Shown while the dialog is closed so a running export stays visible and, once ready, can be
// downloaded in one click. Reads the same cached query as the dialog, so it costs no extra request.
export function ContactExportToast({ exportId, offsetClassName = 'bottom-4', onDismiss }: ContactExportToastProps) {
    const { data } = useContactExport(exportId);
    const download = useDownloadContactExport();

    if (!data) return null;

    const ready = data.status === 'completed' && !data.expired;

    return (
        <div
            role="status"
            className={`fixed right-4 z-40 w-72 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-4 text-[var(--color-ink)] shadow-lg ${offsetClassName}`}
        >
            <div className="mb-1 flex items-center justify-between text-sm">
                <span className="font-medium">Contact export</span>
                <span className="text-[var(--color-ink-muted)]">
                    {data.expired ? 'Expired' : EXPORT_STATUS_LABELS[data.status]}
                </span>
            </div>

            {ready && (
                <p className="text-xs text-[var(--color-ink-muted)]">
                    {data.row_count} {data.row_count === 1 ? 'contact' : 'contacts'}, {EXPORT_FORMAT_LABELS[data.format]}
                </p>
            )}

            {(data.status === 'pending' || data.status === 'processing') && (
                <p className="text-xs text-[var(--color-ink-muted)]">Building your file...</p>
            )}

            {data.status === 'failed' && data.error && <p className="text-xs text-red-600">{data.error}</p>}

            {data.expired && <p className="text-xs text-[var(--color-ink-muted)]">Start a new export to get a fresh file.</p>}

            {download.isError && (
                <p role="alert" className="mt-1 text-xs text-red-600">
                    {exportErrorMessage(download.error)}
                </p>
            )}

            <div className="mt-3 flex items-center gap-3 text-sm">
                {ready && (
                    <button
                        type="button"
                        disabled={download.isPending}
                        onClick={() => download.mutate(data.id)}
                        className="font-medium text-indigo-600 hover:underline disabled:opacity-50"
                    >
                        {download.isPending ? 'Downloading...' : 'Download'}
                    </button>
                )}
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
