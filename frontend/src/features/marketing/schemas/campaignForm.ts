import { z } from 'zod';
import type { CampaignStatus } from './campaign';

// UX layer only: mirrors SaveCampaignRequest (9.1a, 9.4e). The API Form Request is the security boundary,
// and the case-insensitive unique name rule and the tenant-ownership of the audience can only be enforced
// server-side (mapped back via 422). The pickers hold a string id ('' = none) because <select> values are strings.
export const campaignFormSchema = z.object({
    name: z.string().trim().min(1, 'Name is required.').max(160, 'Name must be 160 characters or fewer.'),
    subject: z.string().trim().max(255, 'Subject must be 255 characters or fewer.'),
    segment_id: z.string(),
    email_template_id: z.string(),
});

export type CampaignFormValues = z.infer<typeof campaignFormSchema>;

// What the API receives: an empty subject is sent as null, an empty picker as null (clears the audience).
export type CampaignInput = {
    name: string;
    subject: string | null;
    segment_id: number | null;
    email_template_id: number | null;
};

function toId(value: string): number | null {
    const n = Number(value);
    return value.trim() === '' || !Number.isInteger(n) || n < 1 ? null : n;
}

export function toCampaignInput(values: CampaignFormValues): CampaignInput {
    const subject = values.subject.trim();
    return {
        name: values.name.trim(),
        subject: subject === '' ? null : subject,
        segment_id: toId(values.segment_id),
        email_template_id: toId(values.email_template_id),
    };
}

// 9.4e lifecycle actions that need a confirm dialog (Unschedule is a direct action).
export const LIFECYCLE_KINDS = ['send', 'retry', 'schedule', 'cancel'] as const;
export type LifecycleKind = (typeof LIFECYCLE_KINDS)[number];

export const LIFECYCLE_TARGET: Record<LifecycleKind, CampaignStatus> = {
    send: 'sending',
    retry: 'sending',
    schedule: 'scheduled',
    cancel: 'cancelled',
};

// Schedule form. The value is a <input type="datetime-local"> string (the user's local time); the dialog converts it
// to an ISO instant. The server re-checks that it is in the future (schedule_invalid).
export const scheduleFormSchema = z.object({
    scheduled_at: z
        .string()
        .min(1, 'Pick a date and time.')
        .refine((v) => !Number.isNaN(new Date(v).getTime()), 'Pick a valid date and time.')
        .refine((v) => new Date(v).getTime() > Date.now(), 'Pick a time in the future.'),
});

export type ScheduleFormValues = z.infer<typeof scheduleFormSchema>;