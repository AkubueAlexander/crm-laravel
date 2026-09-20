import { z } from 'zod';
import { paginationMetaSchema } from '@/features/contacts/schemas/contact';

// Mirrors AccountResource::toArray() (app/Http/Resources/AccountResource.php).
// Keep in lockstep with the backend Resource (7.0/14.2): a shape mismatch throws
// loudly via .parse() instead of silently rendering undefined.
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

// Mirrors StoreAccountRequest::rules(). Zod is UX only; the Form Request is the
// security boundary (14.2). Max lengths are assumptions: any backend disagreement
// comes back as a 422 and is mapped onto the field by AccountForm.
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
});

export type CreateAccountInput = z.infer<typeof createAccountSchema>;

// Mirrors UpdateAccountRequest: every field becomes `sometimes` on top of store.
export const updateAccountSchema = createAccountSchema.partial();
export type UpdateAccountInput = z.infer<typeof updateAccountSchema>;

// Mirrors ListAccountsRequest::SORTABLE.
export const ACCOUNT_SORTABLE_COLUMNS = ['name', 'industry', 'created_at'] as const;
export type AccountSortColumn = (typeof ACCOUNT_SORTABLE_COLUMNS)[number];

export const accountsPageSchema = z
    .object({
        data: z.array(accountSchema),
        meta: paginationMetaSchema,
    })
    .passthrough();

export type AccountsPage = z.infer<typeof accountsPageSchema>;