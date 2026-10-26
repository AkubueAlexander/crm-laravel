import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

export const AUTOMATION_STATUSES = ['draft', 'active', 'paused'] as const;
export type AutomationStatus = (typeof AUTOMATION_STATUSES)[number];

export const AUTOMATION_STATUS_LABELS: Record<AutomationStatus, string> = {
    draft: 'Draft',
    active: 'Active',
    paused: 'Paused',
};

export const DELAY_UNITS = ['minutes', 'hours', 'days'] as const;
export type DelayUnit = (typeof DELAY_UNITS)[number];

// Mirrors AutomationResource (9.6a-2). A step is a discriminated union on `type`: the shape of `config`
// depends on it. An unknown step type fails the parse loudly instead of rendering undefined.
const delayStepSchema = z.object({
    position: z.number().int(),
    type: z.literal('delay'),
    config: z.object({ amount: z.number().int(), unit: z.enum(DELAY_UNITS) }),
});

const emailStepSchema = z.object({
    position: z.number().int(),
    type: z.literal('send_email'),
    config: z.object({ email_template_id: z.number().int() }),
});

export const automationStepSchema = z.discriminatedUnion('type', [delayStepSchema, emailStepSchema]);
export type AutomationStep = z.infer<typeof automationStepSchema>;

export const automationSchema = z.object({
    id: z.number(),
    name: z.string(),
    description: z.string().nullable(),
    status: z.enum(AUTOMATION_STATUSES),
    trigger_type: z.string(),
    // {} for triggers without parameters; { segment_id } for the segment trigger.
    trigger_config: z.object({ segment_id: z.number().nullable().optional() }),
    lock_version: z.number().int(),
    steps: z.array(automationStepSchema),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Automation = z.infer<typeof automationSchema>;

export const automationsPageSchema = z.object({
    data: z.array(automationSchema),
    meta: paginationMetaSchema,
});

export type AutomationsPage = z.infer<typeof automationsPageSchema>;

export const AUTOMATION_SORT_COLUMNS = ['name', 'status', 'created_at', 'updated_at'] as const;
export type AutomationSortColumn = (typeof AUTOMATION_SORT_COLUMNS)[number];

// URL-persisted filter/sort/page state for /marketing/automations (8.7). Every field optional; defaults are dropped.
export type AutomationsSearch = {
    q?: string;
    sort?: AutomationSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateAutomationsSearch(search: Record<string, unknown>): AutomationsSearch {
    const out: AutomationsSearch = {};

    // The router JSON-parses search values, so "123" can arrive as a number.
    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (AUTOMATION_SORT_COLUMNS.includes(search.sort as AutomationSortColumn)) out.sort = search.sort as AutomationSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}