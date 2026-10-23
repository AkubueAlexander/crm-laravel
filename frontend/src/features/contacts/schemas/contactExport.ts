import { z } from 'zod';
import type { ContactSortColumn } from './contact';

// Mirrors ContactExportResource and ContactExportProgress (backend 8b.4). The format
// choice is UX only; StoreContactExportRequest is the real boundary (14.2).
export const exportStatusSchema = z.enum(['pending', 'processing', 'completed', 'failed']);
export type ExportStatus = z.infer<typeof exportStatusSchema>;

export const exportFormatSchema = z.enum(['csv', 'xlsx']);
export type ExportFormat = z.infer<typeof exportFormatSchema>;

export const EXPORT_FORMATS: readonly ExportFormat[] = ['csv', 'xlsx'];

export const EXPORT_FORMAT_LABELS: Record<ExportFormat, string> = {
    csv: 'CSV (.csv)',
    xlsx: 'Excel (.xlsx)',
};

export const EXPORT_STATUS_LABELS: Record<ExportStatus, string> = {
    pending: 'Queued',
    processing: 'Preparing',
    completed: 'Ready',
    failed: 'Failed',
};

export function isExportTerminal(status: ExportStatus): boolean {
    return status === 'completed' || status === 'failed';
}

// The list state an export starts from. ContactList publishes it; the export dialog sends it.
export type ContactListQuery = {
    q: string;
    sort: ContactSortColumn;
    direction: 'asc' | 'desc';
};

export const CONTACT_SORT_LABELS: Record<ContactSortColumn, string> = {
    last_name: 'last name',
    first_name: 'first name',
    email: 'email',
    created_at: 'date created',
};

export function describeContactQuery(query: ContactListQuery): string {
    const what = query.q ? `Contacts matching "${query.q}"` : 'All contacts';
    const order = query.direction === 'asc' ? 'ascending' : 'descending';

    return `${what}, sorted by ${CONTACT_SORT_LABELS[query.sort]} (${order}).`;
}

export const contactExportSchema = z.object({
    id: z.number(),
    format: exportFormatSchema,
    filters: z.object({
        q: z.string(),
        sort: z.string(),
        direction: z.enum(['asc', 'desc']),
    }),
    status: exportStatusSchema,
    row_count: z.number(),
    error: z.string().nullable(),
    expired: z.boolean(),
    download_url: z.string().nullable(),
    expires_at: z.string().nullable(),
    started_at: z.string().nullable(),
    finished_at: z.string().nullable(),
    created_at: z.string().nullable(),
});

export type ContactExport = z.infer<typeof contactExportSchema>;

// Pushed on contact-export.progress: status and count only. The download link is never
// pushed; it is minted by the API when the export is read.
export const exportProgressSchema = z.object({
    id: z.number(),
    status: exportStatusSchema,
    row_count: z.number(),
});

export type ExportProgress = z.infer<typeof exportProgressSchema>;

export const contactExportFormSchema = z.object({
    format: exportFormatSchema,
});

export type ContactExportFormInput = z.infer<typeof contactExportFormSchema>;
