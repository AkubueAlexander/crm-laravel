import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import {
    exportErrorMessage,
    useContactExport,
    useDownloadContactExport,
    useStartContactExport,
} from '../api/useContactExport';
import {
    EXPORT_FORMATS,
    EXPORT_FORMAT_LABELS,
    contactExportFormSchema,
    describeContactQuery,
    type ContactExportFormInput,
    type ContactListQuery,
} from '../schemas/contactExport';

type ContactExportDialogProps = {
    exportId: number | null;
    query: ContactListQuery;
    onStarted: (exportId: number) => void;
    onReset: () => void;
    onClose: () => void;
};

const secondaryButton = 'rounded-md border px-3 py-1.5 text-sm font-medium text-[var(--color-ink)]';
const primaryButton = 'rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50';

export function ContactExportDialog({ exportId, query, onStarted, onReset, onClose }: ContactExportDialogProps) {
    const start = useStartContactExport();
    const { data, isError } = useContactExport(exportId);
    const download = useDownloadContactExport();

    // RHF + Zod (8.3). The server re-validates everything (14.2).
    const form = useForm<ContactExportFormInput>({
        resolver: zodResolver(contactExportFormSchema),
        defaultValues: { format: 'csv' },
    });

    const submit = form.handleSubmit((values) => {
        start.mutate(
            { ...query, format: values.format },
            { onSuccess: (created) => onStarted(created.id) },
        );
    });

    if (exportId === null) {
        return (
            <form onSubmit={submit} className="space-y-4">
                <p className="text-sm text-[var(--color-ink)]">{describeContactQuery(query)}</p>

                <fieldset>
                    <legend className="mb-2 text-sm font-medium text-[var(--color-ink)]">File format</legend>
                    <div className="flex gap-4">
                        {EXPORT_FORMATS.map((format) => (
                            <label key={format} className="flex items-center gap-2 text-sm text-[var(--color-ink)]">
                                <input type="radio" value={format} {...form.register('format')} />
                                {EXPORT_FORMAT_LABELS[format]}
                            </label>
                        ))}
                    </div>
                    {form.formState.errors.format && (
                        <p role="alert" className="mt-1 text-xs text-red-600">
                            {form.formState.errors.format.message}
                        </p>
                    )}
                </fieldset>

                <p className="text-xs text-[var(--color-ink-muted)]">
                    The file is built in the background and kept for 24 hours.
                </p>

                {start.isError && (
                    <p role="alert" className="text-sm text-red-600">
                        {exportErrorMessage(start.error)}
                    </p>
                )}

                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className={secondaryButton}>
                        Cancel
                    </button>
                    <button type="submit" disabled={start.isPending} className={primaryButton}>
                        {start.isPending ? 'Starting...' : 'Start export'}
                    </button>
                </div>
            </form>
        );
    }

    if (isError) {
        return (
            <div className="space-y-4">
                <p role="alert" className="text-sm text-red-600">
                    Could not load this export.
                </p>
                <div className="flex justify-end">
                    <button type="button" onClick={onReset} className={secondaryButton}>
                        Start over
                    </button>
                </div>
            </div>
        );
    }

    if (!data) {
        return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;
    }

    if (data.status === 'failed') {
        return (
            <div className="space-y-4">
                <p role="alert" className="text-sm text-red-600">
                    {data.error ?? 'The export failed.'}
                </p>
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className={secondaryButton}>
                        Close
                    </button>
                    <button type="button" onClick={onReset} className={primaryButton}>
                        Try again
                    </button>
                </div>
            </div>
        );
    }

    if (data.status === 'completed' && data.expired) {
        return (
            <div className="space-y-4">
                <p className="text-sm text-[var(--color-ink)]">This export has expired. Start a new one.</p>
                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onClose} className={secondaryButton}>
                        Close
                    </button>
                    <button type="button" onClick={onReset} className={primaryButton}>
                        New export
                    </button>
                </div>
            </div>
        );
    }

    if (data.status === 'completed') {
        return (
            <div className="space-y-4">
                <p className="text-sm text-[var(--color-ink)]">
                    {data.row_count} {data.row_count === 1 ? 'contact' : 'contacts'} exported as{' '}
                    {EXPORT_FORMAT_LABELS[data.format]}. The file is kept for 24 hours.
                </p>

                {download.isError && (
                    <p role="alert" className="text-sm text-red-600">
                        {exportErrorMessage(download.error)}
                    </p>
                )}

                <div className="flex justify-end gap-2">
                    <button type="button" onClick={onReset} className={secondaryButton}>
                        New export
                    </button>
                    <button
                        type="button"
                        disabled={download.isPending}
                        onClick={() => download.mutate(data.id)}
                        className={primaryButton}
                    >
                        {download.isPending ? 'Downloading...' : 'Download'}
                    </button>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            <p role="status" className="text-sm text-[var(--color-ink)]">
                Preparing your export ({data.row_count} {data.row_count === 1 ? 'contact' : 'contacts'})...
            </p>
            <p className="text-xs text-[var(--color-ink-muted)]">
                You can close this window. A download button will appear at the bottom right when it is ready.
            </p>
            <div className="flex justify-end">
                <button type="button" onClick={onClose} className={secondaryButton}>
                    Close
                </button>
            </div>
        </div>
    );
}
