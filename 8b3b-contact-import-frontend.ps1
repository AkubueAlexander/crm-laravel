$ErrorActionPreference = 'Stop'
Write-Host 'crm-erp 8b.3b contact import frontend v1'

$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom {
    param([string]$Path, [string]$Content)
    $full = Join-Path (Get-Location).Path $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $useCrlf = $false
    if (Test-Path $full) {
        $existing = [System.IO.File]::ReadAllText($full)
        if ($existing.Contains("`r`n")) { $useCrlf = $true }
    }
    $text = $Content -replace "`r`n", "`n"
    $text = $text.TrimEnd("`n") + "`n"
    if ($useCrlf) { $text = $text -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $text, $Utf8NoBom)
    Write-Host "  wrote $Path"
}

foreach ($req in @('frontend\src\features\contacts\components\ContactsPage.tsx', 'frontend\src\shared\hooks\useEchoChannel.ts', 'frontend\src\features\notifications\api\useNotificationsLive.ts')) {
    if (-not (Test-Path $req)) { throw "Missing prerequisite: $req (run from the repo root)" }
}

$base = 'frontend\src\features\contacts'

# ---------------------------------------------------------------- 1. Shared echo hook: ref-counted channel leave
# Echo's leave() drops EVERY listener on a channel. The notification bell and the import progress
# both listen on the per-user channel, so leave only when the last hook releases it (6.2 still holds:
# the channel is left deterministically on unmount when nothing else is listening).
Write-NoBom 'frontend\src\shared\hooks\useEchoChannel.ts' @'
import { useEffect, useRef } from 'react';
import { echo } from '@/shared/lib/echo';

const channelRefCounts = new Map<string, number>();

export function useEchoChannel(channel: string, event: string, onEvent: (payload: unknown) => void) {
    // Always call the latest callback without resubscribing on every render.
    const handlerRef = useRef(onEvent);
    useEffect(() => {
        handlerRef.current = onEvent;
    });

    useEffect(() => {
        if (!channel) return;

        const handler = (payload: unknown) => handlerRef.current(payload);

        echo.private(channel).listen(event, handler);
        channelRefCounts.set(channel, (channelRefCounts.get(channel) ?? 0) + 1);

        return () => {
            echo.private(channel).stopListening(event, handler);

            const remaining = (channelRefCounts.get(channel) ?? 1) - 1;
            if (remaining <= 0) {
                channelRefCounts.delete(channel);
                echo.leave(channel);
            } else {
                channelRefCounts.set(channel, remaining);
            }
        };
    }, [channel, event]);
}
'@

# ---------------------------------------------------------------- 2. Schema
Write-NoBom "$base\schemas\contactImport.ts" @'
import { z } from 'zod';

// Mirrors ContactImportResource and ContactImportProgress (backend 8b.3). The size and
// extension rules are UX only; StoreContactImportRequest is the real boundary (14.2).
export const IMPORT_MAX_BYTES = 5 * 1024 * 1024;
export const IMPORT_MAX_ROWS = 5000;

export const importStatusSchema = z.enum(['pending', 'processing', 'completed', 'failed']);
export type ImportStatus = z.infer<typeof importStatusSchema>;

export const IMPORT_STATUS_LABELS: Record<ImportStatus, string> = {
    pending: 'Queued',
    processing: 'Importing',
    completed: 'Finished',
    failed: 'Failed',
};

export function isImportTerminal(status: ImportStatus): boolean {
    return status === 'completed' || status === 'failed';
}

export const importReportEntrySchema = z.object({
    row: z.number(),
    type: z.string(),
    message: z.string(),
    matched_contact_id: z.number().nullish(),
});

export const contactImportSchema = z.object({
    id: z.number(),
    original_name: z.string(),
    status: importStatusSchema,
    total_rows: z.number(),
    processed_rows: z.number(),
    created_rows: z.number(),
    duplicate_rows: z.number(),
    invalid_rows: z.number(),
    report: z.array(importReportEntrySchema),
    error: z.string().nullable(),
    started_at: z.string().nullable(),
    finished_at: z.string().nullable(),
    created_at: z.string().nullable(),
});

export type ContactImport = z.infer<typeof contactImportSchema>;

// Pushed on contact-import.progress: counters only, the report is always read through the API.
export const importProgressSchema = z.object({
    id: z.number(),
    status: importStatusSchema,
    total_rows: z.number(),
    processed_rows: z.number(),
    created_rows: z.number(),
    duplicate_rows: z.number(),
    invalid_rows: z.number(),
});

export type ImportProgress = z.infer<typeof importProgressSchema>;

export const contactImportFormSchema = z.object({
    file: z
        .custom<FileList>((value) => typeof FileList !== 'undefined' && value instanceof FileList && value.length > 0, {
            message: 'Choose a CSV file',
        })
        .refine((files) => !files[0] || files[0].size <= IMPORT_MAX_BYTES, {
            message: 'The file must be 5 MB or smaller',
        })
        .refine((files) => !files[0] || /\.(csv|txt)$/i.test(files[0].name), {
            message: 'Choose a .csv file',
        }),
});

export type ContactImportFormInput = z.infer<typeof contactImportFormSchema>;
'@

# ---------------------------------------------------------------- 3. Query + mutation
Write-NoBom "$base\api\useContactImport.ts" @'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { contactImportSchema, type ContactImport } from '../schemas/contactImport';

// Tenant-namespaced (1.2) and under the 'contacts' prefix, so contact mutations
// that invalidate ['contacts', tenantId] also refresh any open import.
export function contactImportKey(tenantId: string | number | undefined, importId: number | null) {
    return ['contacts', tenantId, 'import', importId] as const;
}

// Kept current by useContactImportLive (Reverb), not by polling. Refetches on window focus.
export function useContactImport(importId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: contactImportKey(tenant?.id, importId),
        queryFn: async ({ signal }): Promise<ContactImport> => {
            const { data } = await apiClient.get(`/contacts/imports/${importId}`, { signal });
            return contactImportSchema.parse(data);
        },
        enabled: !!tenant && importId !== null,
        staleTime: 10_000,
    });
}

export function useStartContactImport() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (file: File): Promise<ContactImport> => {
            const body = new FormData();
            body.append('file', file);
            const { data } = await apiClient.post('/contacts/imports', body);
            return contactImportSchema.parse(data);
        },
        onSuccess: (created) => {
            const key = contactImportKey(tenant?.id, created.id);
            queryClient.setQueryData(key, created);
            // The worker may already have progressed before this response arrived.
            void queryClient.invalidateQueries({ queryKey: key });
        },
    });
}
'@

# ---------------------------------------------------------------- 4. Live hook (6.0 pattern)
Write-NoBom "$base\api\useContactImportLive.ts" @'
import { useQueryClient } from '@tanstack/react-query';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';
import { useTenant } from '@/shared/hooks/useTenant';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import { importProgressSchema, isImportTerminal, type ContactImport } from '../schemas/contactImport';
import { contactImportKey } from './useContactImport';

// The leading dot stops Echo prefixing its App.Events namespace (the event uses broadcastAs).
const PROGRESS_EVENT = '.contact-import.progress';

// 8b.3: patches the cached import with pushed counters, and when it finishes refreshes the
// contacts list so new rows appear without polling. Shares the per-user channel with the
// notification bell (authorized server-side by UserChannel, 1.5).
export function useContactImportLive() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const { data: user } = useCurrentUser();

    const tenantId = tenant?.id;
    const userId = user?.id;
    const channel = tenantId !== undefined && userId !== undefined ? `tenant.${tenantId}.user.${userId}` : '';

    useEchoChannel(channel, PROGRESS_EVENT, (raw) => {
        const parsed = importProgressSchema.safeParse(raw);
        if (!parsed.success) return;

        const progress = parsed.data;

        queryClient.setQueryData<ContactImport>(contactImportKey(tenantId, progress.id), (old) =>
            old ? { ...old, ...progress } : old,
        );

        if (isImportTerminal(progress.status)) {
            // Also refetches the import itself, which is how the report arrives.
            void queryClient.invalidateQueries({ queryKey: ['contacts', tenantId] });
        }
    });
}
'@

# ---------------------------------------------------------------- 5. Dialog
Write-NoBom "$base\components\ContactImportDialog.tsx" @'
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
'@

# ---------------------------------------------------------------- 6. Toast
Write-NoBom "$base\components\ContactImportToast.tsx" @'
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
'@

# ---------------------------------------------------------------- 7. ContactsPage (full rewrite of the file you pasted)
Write-NoBom "$base\components\ContactsPage.tsx" @'
import { useState } from 'react';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { Timeline } from '@/features/activities/components/Timeline';
import { ContactList } from './ContactList';
import { ContactForm } from './ContactForm';
import { ContactMatchSettingsForm } from './ContactMatchSettingsForm';
import { ContactImportDialog } from './ContactImportDialog';
import { ContactImportToast } from './ContactImportToast';
import { useContactImportLive } from '../api/useContactImportLive';
import type { Contact } from '../schemas/contact';

type ModalState =
    | { mode: 'create' }
    | { mode: 'edit'; contact: Contact }
    | { mode: 'activity'; contact: Contact }
    | { mode: 'settings' }
    | { mode: 'import' }
    | null;

function contactLabel(contact: Contact): string {
    return [contact.first_name, contact.last_name].filter(Boolean).join(' ') || 'Contact';
}

function modalTitle(modal: ModalState): string {
    if (modal?.mode === 'edit') return 'Edit contact';
    if (modal?.mode === 'settings') return 'Contact settings';
    if (modal?.mode === 'import') return 'Import contacts';
    if (modal?.mode === 'activity') return `Activity - ${contactLabel(modal.contact)}`;
    return 'New contact';
}

export function ContactsPage() {
    const [modal, setModal] = useState<ModalState>(null);
    // UI-only: which import the dialog/toast follow. The import itself is server state (8.1).
    const [importId, setImportId] = useState<number | null>(null);
    // Display-only (2.3): the API re-checks activities.view and contacts.view.
    const canViewActivities = usePermission('activities.view');
    // Display-only (2.3): POST /contacts/imports re-checks contacts.create server-side and in the job.
    const canImport = usePermission('contacts.create');

    // Live progress for the import (8b.3), pushed over the per-user channel.
    useContactImportLive();

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Contacts</h1>
                <div className="flex items-center gap-2">
                    {canImport && (
                        <button
                            onClick={() => setModal({ mode: 'import' })}
                            className="rounded-md border px-3 py-1.5 text-sm font-medium text-[var(--color-ink)]"
                        >
                            Import CSV
                        </button>
                    )}
                    <button
                        onClick={() => setModal({ mode: 'settings' })}
                        className="rounded-md border px-3 py-1.5 text-sm font-medium text-[var(--color-ink)]"
                    >
                        Settings
                    </button>
                    <button
                        onClick={() => setModal({ mode: 'create' })}
                        className="rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white"
                    >
                        New contact
                    </button>
                </div>
            </div>

            <ContactList onSelectContact={(contact) => setModal({ mode: 'edit', contact })} />

            {importId !== null && modal?.mode !== 'import' && (
                <ContactImportToast
                    importId={importId}
                    onOpen={() => setModal({ mode: 'import' })}
                    onDismiss={() => setImportId(null)}
                />
            )}

            <Modal open={modal !== null} onClose={() => setModal(null)} title={modalTitle(modal)}>
                {modal?.mode === 'settings' && <ContactMatchSettingsForm />}

                {modal?.mode === 'import' && (
                    <ContactImportDialog
                        importId={importId}
                        onStarted={setImportId}
                        onReset={() => setImportId(null)}
                        onClose={() => setModal(null)}
                    />
                )}

                {(modal?.mode === 'create' || modal?.mode === 'edit') && (
                    <ContactForm
                        contact={modal.mode === 'edit' ? modal.contact : undefined}
                        onSuccess={() => setModal(null)}
                    />
                )}

                {modal?.mode === 'edit' && canViewActivities && (
                    <div className="mt-6 border-t pt-4">
                        <button
                            type="button"
                            onClick={() => setModal({ mode: 'activity', contact: modal.contact })}
                            className="text-sm font-medium text-indigo-600 hover:underline"
                        >
                            View activity
                        </button>
                    </div>
                )}

                {modal?.mode === 'activity' && (
                    <div>
                        <button
                            type="button"
                            onClick={() => setModal({ mode: 'edit', contact: modal.contact })}
                            className="mb-3 text-sm text-indigo-600 hover:underline"
                        >
                            &larr; Back to contact
                        </button>
                        <div className="max-h-[60vh] overflow-y-auto pr-1">
                            <Timeline key={`contact-${modal.contact.id}`} targetType="contact" targetId={modal.contact.id} />
                        </div>
                    </div>
                )}
            </Modal>
        </div>
    );
}
'@

Write-Host ''
Write-Host 'Done. Next: cd frontend ; npx tsc --noEmit'
Write-Host 'NOTE: rewrote two files you pasted (ContactsPage.tsx, useEchoChannel.ts); not shown, so not touched: Board.tsx (its useEchoChannel call is unchanged and still leaves the channel on unmount), ContactList.tsx, and whether Reverb + a queue worker are running locally.'
