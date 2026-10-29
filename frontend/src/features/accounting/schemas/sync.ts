import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// 12.2c: ledger sync status + run history (GET accounting/sync, GET accounting/sync/runs).
export const SYNC_RUN_STATUSES = ['queued', 'running', 'succeeded', 'failed'] as const;

export const syncProviderSchema = z.object({
    id: z.string(),
    label: z.string(),
    configured: z.boolean(),
});

export const syncRunSchema = z.object({
    id: z.number(),
    provider: z.string(),
    status: z.enum(SYNC_RUN_STATUSES),
    requested_by: z.number().nullable().optional(),
    started_at: z.string().nullable().optional(),
    finished_at: z.string().nullable().optional(),
    error: z.string().nullable().optional(),
    accounts_synced: z.number().default(0),
    entries_synced: z.number().default(0),
    to_entry_id: z.number().nullable().optional(),
    created_at: z.string().nullable().optional(),
});

export const syncStatusSchema = z.object({
    provider: z.string(),
    providers: z.array(syncProviderSchema),
    last_synced_at: z.string().nullable(),
    last_run: syncRunSchema.nullable(),
    active_run: syncRunSchema.nullable(),
});

export const syncRunsPageSchema = z.object({
    data: z.array(syncRunSchema),
    meta: paginationMetaSchema,
});

export type SyncProvider = z.infer<typeof syncProviderSchema>;
export type SyncRun = z.infer<typeof syncRunSchema>;
export type SyncStatus = z.infer<typeof syncStatusSchema>;
export type SyncRunsPage = z.infer<typeof syncRunsPageSchema>;

// One Zod schema per form (8.3). The Laravel Action stays the real boundary.
export const syncProviderFormSchema = z.object({
    provider: z.string().min(1, 'Choose a provider'),
});
export type SyncProviderFormValues = z.infer<typeof syncProviderFormSchema>;

export type SyncSearch = { page?: number };

// Never throws (8.7).
export function validateSyncSearch(raw: Record<string, unknown>): SyncSearch {
    const n = Number(raw.page);
    return Number.isInteger(n) && n > 1 ? { page: n } : {};
}

export function formatDateTime(iso: string | null | undefined): string {
    if (!iso) return '-';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '-' : d.toLocaleString();
}
