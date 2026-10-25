import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

export const SEGMENT_MATCH_MODES = ['all', 'any'] as const;
export type SegmentMatchMode = (typeof SEGMENT_MATCH_MODES)[number];

// Mirrors one stored condition (SegmentResource, 9.2b). field/operator stay plain strings here: the allowed
// values come from GET /segments/fields (the builder in 9.2d), so the SPA never hardcodes the catalog.
export const segmentConditionSchema = z.object({
    field: z.string(),
    operator: z.string(),
    value: z.union([z.string(), z.number()]).nullable(),
});

export type SegmentCondition = z.infer<typeof segmentConditionSchema>;

// Mirrors SegmentResource (9.2b).
export const segmentSchema = z.object({
    id: z.number(),
    name: z.string(),
    description: z.string().nullable(),
    match_mode: z.enum(SEGMENT_MATCH_MODES),
    conditions: z.array(segmentConditionSchema),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Segment = z.infer<typeof segmentSchema>;

export const segmentsPageSchema = z.object({
    data: z.array(segmentSchema),
    meta: paginationMetaSchema,
});

export type SegmentsPage = z.infer<typeof segmentsPageSchema>;

export const SEGMENT_SORT_COLUMNS = ['name', 'match_mode', 'created_at', 'updated_at'] as const;
export type SegmentSortColumn = (typeof SEGMENT_SORT_COLUMNS)[number];

// URL-persisted filter/sort/page state for /marketing/segments (8.7). Every field optional; defaults are dropped.
export type SegmentsSearch = {
    q?: string;
    sort?: SegmentSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateSegmentsSearch(search: Record<string, unknown>): SegmentsSearch {
    const out: SegmentsSearch = {};

    // The router JSON-parses search values, so "123" can arrive as a number.
    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (SEGMENT_SORT_COLUMNS.includes(search.sort as SegmentSortColumn)) out.sort = search.sort as SegmentSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}
