import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

export const ACTIVITY_TYPES = ['call', 'email', 'meeting', 'task', 'note'] as const;
export type ActivityType = (typeof ACTIVITY_TYPES)[number];

// Aliases from Activity::TARGETS on the backend. The client never sends a class name.
export const ACTIVITY_TARGET_TYPES = ['lead', 'contact', 'account', 'deal'] as const;
export type ActivityTargetType = (typeof ACTIVITY_TARGET_TYPES)[number];

// Mirrors ActivityResource::toArray() (app/Http/Resources/ActivityResource.php).
export const activitySchema = z.object({
    id: z.number(),
    activityable_type: z.enum(ACTIVITY_TARGET_TYPES),
    activityable_id: z.number(),
    type: z.enum(ACTIVITY_TYPES),
    subject: z.string(),
    body: z.string().nullable(),
    due_at: z.string().nullable(),
    completed_at: z.string().nullable(),
    is_completed: z.boolean(),
    owner_id: z.number().nullable(),
    owner: z.object({ id: z.number(), name: z.string() }).nullable().optional(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Activity = z.infer<typeof activitySchema>;

export const activitiesPageSchema = z
    .object({
        data: z.array(activitySchema),
        meta: paginationMetaSchema,
    })
    .passthrough();

export type ActivitiesPage = z.infer<typeof activitiesPageSchema>;

// Form schema. Zod is UX only: StoreActivityRequest / UpdateActivityRequest are the
// boundary (14.2). due_at holds a <input type="datetime-local"> value.
export const activityFormSchema = z.object({
    type: z.enum(ACTIVITY_TYPES),
    subject: z.string().trim().min(1, 'Subject is required').max(255, 'Subject is too long (max 255)'),
    body: z.string().max(10000, 'Notes are too long (max 10000)').optional(),
    due_at: z.string().optional(),
    owner_id: z.number().int().nullable().optional(),
});

export type ActivityFormValues = z.infer<typeof activityFormSchema>;

// What goes over the wire. Empty optional fields are null / omitted.
export type ActivityPayload = {
    type: ActivityType;
    subject: string;
    body?: string | null;
    due_at?: string | null;
    owner_id?: number | null;
};

export type CreateActivityInput = ActivityPayload & {
    activityable_type: ActivityTargetType;
    activityable_id: number;
};

// datetime-local ("2026-10-22T14:30", local time) <-> ISO 8601 (UTC).
export function localInputToIso(value: string): string | null {
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? null : d.toISOString();
}

export function isoToLocalInput(iso: string): string {
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
}
