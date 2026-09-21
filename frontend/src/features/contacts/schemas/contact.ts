import { z } from 'zod';

// Mirrors ContactResource::toArray() exactly (app/Http/Resources/ContactResource.php).
// Keep this whitelist in lockstep with the backend Resource (7.0/14.2) — a shape
// mismatch throws loudly via .parse() rather than silently rendering undefined.
export const contactSchema = z.object({
    id: z.number(),
    first_name: z.string().nullable(),
    last_name: z.string(),
    full_name: z.string(),
    email: z.string().nullable(),
    phone: z.string().nullable(),
    job_title: z.string().nullable(),
    account_id: z.number().nullable(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Contact = z.infer<typeof contactSchema>;

// Mirrors StoreContactRequest::rules(). Zod here is UX only; the Form Request
// remains the real security boundary (14.2) — this never replaces it.
const phoneRegex = /^[\d\s+().\-x#]+$/i;

export const createContactSchema = z.object({
    first_name: z.string().max(100).nullable().optional(),
    last_name: z.string().min(1, 'Last name is required').max(100),
    email: z.string().email('Enter a valid email').max(255).nullable().optional().or(z.literal('')),
    phone: z.string().max(50).regex(phoneRegex, 'Enter a valid phone number').nullable().optional().or(z.literal('')),
    job_title: z.string().max(150).nullable().optional(),
    // Mirrors the tenant-scoped exists rule; the backend is the real check (14.2).
    account_id: z.number().int().nullable().optional(),
});

export type CreateContactInput = z.infer<typeof createContactSchema>;

// Mirrors UpdateContactRequest::rules() — every field becomes `sometimes` on top
// of the store rule, so last_name is optional here unlike on create.
export const updateContactSchema = createContactSchema.partial();
export type UpdateContactInput = z.infer<typeof updateContactSchema>;

// Mirrors ListContactsRequest::SORTABLE.
export const CONTACT_SORTABLE_COLUMNS = ['last_name', 'first_name', 'email', 'created_at'] as const;
export type ContactSortColumn = (typeof CONTACT_SORTABLE_COLUMNS)[number];

// Laravel's default paginated-resource shape. .passthrough() tolerates extra
// fields (e.g. links, path) without failing the parse on fields we don't use.
export const paginationMetaSchema = z
    .object({
        current_page: z.number(),
        last_page: z.number(),
        per_page: z.number(),
        total: z.number(),
        from: z.number().nullable(),
        to: z.number().nullable(),
    })
    .passthrough();

export const contactsPageSchema = z
    .object({
        data: z.array(contactSchema),
        meta: paginationMetaSchema,
    })
    .passthrough();

export type ContactsPage = z.infer<typeof contactsPageSchema>;

// Mirrors CheckContactDuplicatesRequest::rules(). Deliberately permissive shape
// (half-typed input must not 422 — the request comment says as much).
export const duplicateCheckInputSchema = z.object({
    first_name: z.string().max(100).nullable().optional(),
    last_name: z.string().max(100).nullable().optional(),
    email: z.string().max(255).nullable().optional(),
    phone: z.string().max(50).nullable().optional(),
    exclude_contact_id: z.number().nullable().optional(),
});

export type DuplicateCheckInput = z.infer<typeof duplicateCheckInputSchema>;

// ASSUMPTION, not yet verified against a live response: `reasons` is treated as
// string[]. Confirm this against the real POST /contacts/check-duplicates
// payload before relying on it in the UI — flagging rather than guessing silently.
export const duplicateMatchSchema = z.object({
    contact: contactSchema,
    score: z.number(),
    reasons: z.array(z.string()),
});

export const duplicateCheckResponseSchema = z.object({
    data: z.array(duplicateMatchSchema),
    threshold: z.number(),
});

export type DuplicateMatch = z.infer<typeof duplicateMatchSchema>;
export type DuplicateCheckResponse = z.infer<typeof duplicateCheckResponseSchema>;