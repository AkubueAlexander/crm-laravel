import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// Mirrors AuditEntryResource (8c.3a).
export const auditEntrySchema = z.object({
    id: z.string(),
    source: z.string(),
    event: z.string(),
    occurred_at: z.string(),
    actor: z.object({ id: z.number(), name: z.string() }).nullable(),
    subject: z
        .object({ type: z.string(), id: z.number().nullable(), label: z.string().nullable() })
        .nullable(),
    details: z.record(z.unknown()),
});

export type AuditEntry = z.infer<typeof auditEntrySchema>;

export const auditPageSchema = z.object({
    data: z.array(auditEntrySchema),
    meta: paginationMetaSchema,
});

export type AuditPage = z.infer<typeof auditPageSchema>;

// GET /audit-log/actors: the users who appear in the feed (options for the "user" filter).
export const auditActorSchema = z.object({ id: z.number(), name: z.string() });
export type AuditActor = z.infer<typeof auditActorSchema>;

export const auditActorsResponseSchema = z.object({ data: z.array(auditActorSchema) });

export const AUDIT_SOURCES = ['deal_stage', 'users'] as const;
export type AuditSource = (typeof AUDIT_SOURCES)[number];

// URL-persisted filter/page state for /audit (8.7). Every field optional; defaults are dropped.
export type AuditSearch = {
    source?: AuditSource;
    user?: number;
    from?: string;
    to?: string;
    page?: number;
};

const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateAuditSearch(search: Record<string, unknown>): AuditSearch {
    const out: AuditSearch = {};

    if (AUDIT_SOURCES.includes(search.source as AuditSource)) out.source = search.source as AuditSource;

    const user = Number(search.user);
    if (Number.isInteger(user) && user > 0) out.user = user;

    if (typeof search.from === 'string' && DATE_RE.test(search.from)) out.from = search.from;
    if (typeof search.to === 'string' && DATE_RE.test(search.to)) out.to = search.to;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}
