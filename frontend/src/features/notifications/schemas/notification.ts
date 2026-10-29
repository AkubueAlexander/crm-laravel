import { z } from 'zod';

// Mirrors NotificationResource (app/Http/Resources). tenant_id is never sent to the client.
// An empty PHP array serializes as [] rather than {}, so normalize it before parsing.
export const notificationDataSchema = z
    .object({
        subject: z.string().nullish(),
        due_at: z.string().nullish(),
        target_type: z.string().nullish(),
        target_id: z.number().nullish(),
        activity_id: z.number().nullish(),
        activity_type: z.string().nullish(),
        item_id: z.number().nullish(),
        sku: z.string().nullish(),
        name: z.string().nullish(),
        unit: z.string().nullish(),
        on_hand: z.string().nullish(),
        reorder_level: z.string().nullish(),
    })
    .passthrough();

export const notificationSchema = z.object({
    id: z.string(),
    kind: z.string(),
    data: z.preprocess((value) => (Array.isArray(value) ? {} : value), notificationDataSchema),
    read_at: z.string().nullable(),
    created_at: z.string().nullable(),
});

export type AppNotification = z.infer<typeof notificationSchema>;

export const notificationsResponseSchema = z.object({
    data: z.array(notificationSchema),
    meta: z.object({
        current_page: z.number(),
        last_page: z.number(),
        per_page: z.number(),
        total: z.number(),
        unread_count: z.number(),
    }),
});

export type NotificationsResponse = z.infer<typeof notificationsResponseSchema>;

export const NOTIFICATIONS_PER_PAGE = 15;
export const OVERDUE_KIND = 'activity_overdue';
export const LOW_STOCK_KIND = 'low_stock';
