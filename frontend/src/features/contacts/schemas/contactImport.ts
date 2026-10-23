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
