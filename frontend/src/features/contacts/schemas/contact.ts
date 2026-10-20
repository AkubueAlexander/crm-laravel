import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';


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


export const updateContactSchema = createContactSchema.partial();
export type UpdateContactInput = z.infer<typeof updateContactSchema>;


export const CONTACT_SORTABLE_COLUMNS = ['last_name', 'first_name', 'email', 'created_at'] as const;
export type ContactSortColumn = (typeof CONTACT_SORTABLE_COLUMNS)[number];

export const contactsPageSchema = z
    .object({
        data: z.array(contactSchema),
        meta: paginationMetaSchema,
    })
    .passthrough();

export type ContactsPage = z.infer<typeof contactsPageSchema>;


export const duplicateCheckInputSchema = z.object({
    first_name: z.string().max(100).nullable().optional(),
    last_name: z.string().max(100).nullable().optional(),
    email: z.string().max(255).nullable().optional(),
    phone: z.string().max(50).nullable().optional(),
    exclude_contact_id: z.number().nullable().optional(),
});

export type DuplicateCheckInput = z.infer<typeof duplicateCheckInputSchema>;

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
