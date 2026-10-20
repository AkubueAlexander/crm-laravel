import { z } from 'zod';


export const paginationMetaSchema = z.object({
    current_page: z.number(),
    from: z.number().nullable(),
    last_page: z.number(),
    per_page: z.number(),
    to: z.number().nullable(),
    total: z.number(),
});

export type PaginationMeta = z.infer<typeof paginationMetaSchema>;
