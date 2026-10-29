import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// 13.2b: leave requests. Mirrors LeaveRequestResource.
export const LEAVE_TYPES = ['annual', 'sick', 'unpaid', 'other'] as const;
export const LEAVE_STATUSES = ['pending', 'approved', 'rejected', 'cancelled'] as const;
export type LeaveType = (typeof LEAVE_TYPES)[number];
export type LeaveStatus = (typeof LEAVE_STATUSES)[number];

export const LEAVE_TYPE_LABELS: Record<LeaveType, string> = {
    annual: 'Annual',
    sick: 'Sick',
    unpaid: 'Unpaid',
    other: 'Other',
};

export const LEAVE_STATUS_LABELS: Record<LeaveStatus, string> = {
    pending: 'Pending',
    approved: 'Approved',
    rejected: 'Rejected',
    cancelled: 'Cancelled',
};

export const leaveRequestSchema = z.object({
    id: z.number(),
    employee_id: z.number(),
    employee: z.object({ id: z.number(), name: z.string() }).nullable(),
    type: z.enum(LEAVE_TYPES),
    start_date: z.string(),
    end_date: z.string(),
    days: z.number(),
    reason: z.string().nullable(),
    status: z.enum(LEAVE_STATUSES),
    lock_version: z.number(),
    requested_by: z.unknown(),
    decided_by: z.unknown(),
    decided_at: z.string().nullable().optional(),
    decision_note: z.string().nullable(),
    created_at: z.string().nullable().optional(),
    updated_at: z.string().nullable().optional(),
});

export const leaveRequestsPageSchema = z.object({
    data: z.array(leaveRequestSchema),
    meta: paginationMetaSchema,
});

export type LeaveRequest = z.infer<typeof leaveRequestSchema>;
export type LeaveRequestsPage = z.infer<typeof leaveRequestsPageSchema>;

const isoDate = z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use a valid date');

// One Zod schema per form (8.3). The Laravel Actions stay the real boundary.
export const leaveFormSchema = z
    .object({
        employee_id: z.string().min(1, 'Select an employee'),
        type: z.enum(LEAVE_TYPES),
        start_date: isoDate,
        end_date: isoDate,
        reason: z.string().trim().max(1000, 'Max 1000 characters'),
    })
    .refine((v) => v.end_date >= v.start_date, { message: 'End date must be on or after the start date', path: ['end_date'] });
export type LeaveFormValues = z.infer<typeof leaveFormSchema>;

export function leaveToForm(r: LeaveRequest | null, defaultEmployeeId?: number | null): LeaveFormValues {
    return {
        employee_id: r ? String(r.employee_id) : defaultEmployeeId ? String(defaultEmployeeId) : '',
        type: r?.type ?? 'annual',
        start_date: r?.start_date ?? '',
        end_date: r?.end_date ?? '',
        reason: r?.reason ?? '',
    };
}

export const rejectFormSchema = z.object({
    note: z.string().trim().min(1, 'Enter a reason').max(500, 'Max 500 characters'),
});
export type RejectFormValues = z.infer<typeof rejectFormSchema>;

// URL search state (8.7). Never throws; the router may hand numbers for numeric-looking values.
export type LeaveSearch = { status?: LeaveStatus; sort?: 'start_date'; page?: number };

export function validateLeaveSearch(raw: Record<string, unknown>): LeaveSearch {
    const out: LeaveSearch = {};
    if (typeof raw.status === 'string' && (LEAVE_STATUSES as readonly string[]).includes(raw.status)) out.status = raw.status as LeaveStatus;
    if (raw.sort === 'start_date') out.sort = 'start_date';
    const page = Number(raw.page);
    if (Number.isInteger(page) && page > 1) out.page = page;
    return out;
}
