import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isApiError } from '@/shared/lib/apiClient';
import { useContactImport, useStartContactImport } from '../api/useContactImport';
import {
    IMPORT_MAX_ROWS,
    IMPORT_STATUS_LABELS,
    contactImportFormSchema,
    isImportTerminal,
    type ContactImportFormInput,
    type ImportStatus,
} from '../schemas/contactImport';

const SAMPLE_HREF =
    'data:text/csv;charset=utf-8,' +
    encodeURIComponent('first_name,last_name,email,phone,job_title\nJane,Smith,jane@example.com,555-123-4567,Buyer\n');

type ContactImportDialogProps = {
    importId: number | null;
    onStarted: (importId: number) => void;
    onReset: () => void;
    onClose: () => void;
};

// Which step shows is derived from whether an import exists; the import itself is server state.
export function ContactImportDialog({ importId, onStarted, onReset, onClose }: ContactImportDialogProps) {
    if (importId === null) {
        return <UploadStep onStarted={onStarted} onCancel={onClose} />;
    }

    return <ProgressStep importId={importId} onReset={onReset} onClose={onClose} />;
}

function UploadStep({ onStarted, onCancel }: { onStarted: (importId: number) => void; onCancel: () => void }) {
    const startImport = useStartContactImport();

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<ContactImportFormInput>({
        resolver: zodResolver(contactImportFormSchema),
    });

    async function onSubmit(values: ContactImportFormInput) {
        const file = values.file[0];
        if (!file) return;

        try {
            const created = await startImport.mutateAsync(file);
            onStarted(created.id);
        } catch (err) {
            // 14.3: surface the backend's { message, errors, code } shape under the field.
            if (isApiError(err)) {
                const fileErrors = err.response?.data.errors?.file;
                setError('file', { message: fileErrors?.[0] ?? err.response?.data.message ?? 'Upload failed.' });
            } else {
                setError('file', { message: 'Upload failed. Check your connection and try again.' });
            }
        }
    }

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <p className="text-sm text-[var(--color-ink-muted)]">
                Upload a CSV with a header row. Columns: first_name, last_name (required), email, phone, job_title.
                Up to {IMPORT_MAX_ROWS.toLocaleString()} rows and 5 MB. Possible duplicates are skipped and listed, not created.{' '}
                <a
                    href={SAMPLE_HREF}
                    download="contacts-sample.csv"
                    className="font-medium text-indigo-600 hover:underline"
                >
                    Download a sample
                </a>
            </p>

            <div>
                <label htmlFor="import_file" className="block text-sm font-medium">
                    CSV file
                </label>
                <input
                    id="import_file"
                    type="file"
                    accept=".csv,.txt,text/csv"
                    {...register('file')}
                    className="mt-1 block w-full text-sm"
                />
                {errors.file && <p className="mt-1 text-sm text-red-600">{errors.file.message}</p>}
            </div>

            <div className="flex items-center gap-3">
                <button
                    type="submit"
                    disabled={isSubmitting || startImport.isPending}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    {startImport.isPending ? 'Uploading...' : 'Start import'}
                </button>
                <button
                    type="button"
                    onClick={onCancel}
                    className="rounded-md border px-4 py-2 text-sm font-medium text-[var(--color-ink)]"
                >
                    Cancel
                </button>
            </div>
        </form>
    );
}

function ProgressStep({
    importId,
    onReset,
    onClose,
}: {
    importId: number;
    onReset: () => void;
    onClose: () => void;
}) {
    const { data, isPending, isError, isFetching, refetch } = useContactImport(importId);

    if (isPending) {
        return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;
    }

    if (isError || !data) {
        return (
            <div className="space-y-3">
                <p className="text-sm text-red-600">Could not load this import.</p>
                <button type="button" onClick={() => void refetch()} className="text-sm font-medium text-indigo-600 hover:underline">
                    Try again
                </button>
            </div>
        );
    }

    const terminal = isImportTerminal(data.status);
    const skipped = data.duplicate_rows + data.invalid_rows;

    return (
        <div className="space-y-4">
            <div>
                <div className="mb-1 flex items-center justify-between text-sm">
                    <span className="truncate font-medium">{data.original_name}</span>
                    <span className="text-[var(--color-ink-muted)]">{IMPORT_STATUS_LABELS[data.status]}</span>
                </div>
                <ImportProgressBar processed={data.processed_rows} total={data.total_rows} status={data.status} />
                <p className="mt-1 text-xs text-[var(--color-ink-muted)]">
                    {data.processed_rows} of {data.total_rows} rows processed
                </p>
            </div>

            <dl className="grid grid-cols-3 gap-3 text-center text-sm">
                <Stat label="Created" value={data.created_rows} />
                <Stat label="Duplicates skipped" value={data.duplicate_rows} />
                <Stat label="Invalid skipped" value={data.invalid_rows} />
            </dl>

            {data.status === 'failed' && data.error && (
                <p role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800">
                    {data.error}
                </p>
            )}

            {terminal && data.report.length > 0 && (
                <div>
                    <p className="mb-1 text-sm font-medium">Skipped rows</p>
                    <div className="max-h-56 overflow-auto rounded-md border border-[var(--color-border)]">
                        <table className="w-full text-left text-xs">
                            <thead className="sticky top-0 bg-[var(--color-surface)]">
                                <tr>
                                    <th className="px-2 py-1.5 font-medium">Row</th>
                                    <th className="px-2 py-1.5 font-medium">Issue</th>
                                    <th className="px-2 py-1.5 font-medium">Detail</th>
                                </tr>
                            </thead>
                            <tbody>
                                {data.report.map((entry) => (
                                    <tr key={`${entry.row}-${entry.type}`} className="border-t border-[var(--color-border)]">
                                        <td className="px-2 py-1.5">{entry.row}</td>
                                        <td className="px-2 py-1.5 capitalize">{entry.type}</td>
                                        <td className="px-2 py-1.5">{entry.message}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    {skipped > data.report.length && (
                        <p className="mt-1 text-xs text-[var(--color-ink-muted)]">
                            Showing the first {data.report.length} of {skipped} skipped rows.
                        </p>
                    )}
                </div>
            )}

            <div className="flex items-center gap-3">
                {terminal ? (
                    <>
                        <button
                            type="button"
                            onClick={onReset}
                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white"
                        >
                            Import another file
                        </button>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border px-4 py-2 text-sm font-medium text-[var(--color-ink)]"
                        >
                            Close
                        </button>
                    </>
                ) : (
                    <>
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md border px-4 py-2 text-sm font-medium text-[var(--color-ink)]"
                        >
                            Close (keeps running)
                        </button>
                        <button
                            type="button"
                            onClick={() => void refetch()}
                            disabled={isFetching}
                            className="text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] disabled:opacity-50"
                        >
                            Refresh status
                        </button>
                    </>
                )}
            </div>
        </div>
    );
}

function Stat({ label, value }: { label: string; value: number }) {
    return (
        <div className="rounded-md border border-[var(--color-border)] p-2">
            <dd className="text-lg font-medium">{value}</dd>
            <dt className="text-xs text-[var(--color-ink-muted)]">{label}</dt>
        </div>
    );
}

export function ImportProgressBar({
    processed,
    total,
    status,
}: {
    processed: number;
    total: number;
    status: ImportStatus;
}) {
    const percent = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : status === 'completed' ? 100 : 0;

    return (
        <div
            role="progressbar"
            aria-valuemin={0}
            aria-valuemax={100}
            aria-valuenow={percent}
            className="h-2 w-full overflow-hidden rounded bg-[var(--color-paper)]"
        >
            <div
                className={`h-full transition-all ${status === 'failed' ? 'bg-red-500' : 'bg-[var(--color-accent)]'}`}
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}
