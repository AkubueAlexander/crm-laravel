import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';


export const accountSchema = z.object({
    id: z.number(),
    name: z.string(),
    industry: z.string().nullable(),
    website: z.string().nullable(),
    phone: z.string().nullable(),
    owner_id: z.number().nullable(),
    owner: z.object({ id: z.number(), name: z.string() }).nullable().optional(),
    contacts_count: z.number(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Account = z.infer<typeof accountSchema>;


export const createAccountSchema = z.object({
    name: z.string().trim().min(1, 'Name is required').max(255),
    industry: z.string().max(100).nullable().optional(),
    website: z
        .string()
        .max(255)
        .url('Enter a full URL, e.g. https://example.com')
        .nullable()
        .optional()
        .or(z.literal('')),
    phone: z.string().max(50).nullable().optional(),
    owner_id: z.number().int().nullable().optional(),
});

export type CreateAccountInput = z.infer<typeof createAccountSchema>;

// Mirrors UpdateAccountRequest: every field becomes `sometimes` on top of store.
export const updateAccountSchema = createAccountSchema.partial();
export type UpdateAccountInput = z.infer<typeof updateAccountSchema>;


export const ACCOUNT_SORTABLE_COLUMNS = ['name', 'industry', 'created_at'] as const;
export type AccountSortColumn = (typeof ACCOUNT_SORTABLE_COLUMNS)[number];

export const accountsPageSchema = z
    .object({
        data: z.array(accountSchema),
        meta: paginationMetaSchema,
    })
    .passthrough();

export type AccountsPage = z.infer<typeof accountsPageSchema>;
