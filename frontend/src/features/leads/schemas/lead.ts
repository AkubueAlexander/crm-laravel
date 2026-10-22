import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// Mirrors LeadResource::toArray() (app/Http/Resources/LeadResource.php).
export const leadSchema = z.object({
    id: z.number(),
    first_name: z.string(),
    last_name: z.string().nullable(),
    name: z.string(), // computed: first+last trimmed
    email: z.string().nullable(),
    phone: z.string().nullable(),
    company: z.string().nullable(),
    source: z.string().nullable(),
    status: z.enum(['new', 'contacted', 'qualified', 'disqualified', 'converted']),
    owner_id: z.number().nullable(),
    owner: z.object({ id: z.number(), name: z.string() }).nullable().optional(),
    converted_at: z.string().nullable(),
    converted_contact_id: z.number().nullable(),
    converted_account_id: z.number().nullable(),
    converted_deal_id: z.number().nullable(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Lead = z.infer<typeof leadSchema>;


export const LEAD_ASSIGNABLE_STATUSES = ['new', 'contacted', 'qualified', 'disqualified'] as const;
export type LeadAssignableStatus = (typeof LEAD_ASSIGNABLE_STATUSES)[number];


export const LEAD_FILTERABLE_STATUSES = [...LEAD_ASSIGNABLE_STATUSES, 'converted'] as const;

export const createLeadSchema = z.object({
    first_name: z.string().trim().min(1, 'First name is required').max(255),
    last_name: z.string().max(255).nullable().optional(),
    email: z.string().max(255).email('Enter a valid email').nullable().optional().or(z.literal('')),
    phone: z.string().max(50).nullable().optional(),
    company: z.string().max(255).nullable().optional(),
    source: z.string().max(100).nullable().optional(),
    status: z.enum(LEAD_ASSIGNABLE_STATUSES).optional(),
    owner_id: z.number().int().nullable().optional(),
});

export type CreateLeadInput = z.infer<typeof createLeadSchema>;

export const updateLeadSchema = createLeadSchema.partial();
export type UpdateLeadInput = z.infer<typeof updateLeadSchema>;


export const LEAD_SORTABLE_COLUMNS = ['first_name', 'last_name', 'company', 'status', 'created_at'] as const;
export type LeadSortColumn = (typeof LEAD_SORTABLE_COLUMNS)[number];

export const leadsPageSchema = z
    .object({
        data: z.array(leadSchema),
        meta: paginationMetaSchema,
    })
    .passthrough();

export type LeadsPage = z.infer<typeof leadsPageSchema>;
