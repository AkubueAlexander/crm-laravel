import { z } from 'zod';


export const ownerOptionSchema = z.object({
    id: z.number(),
    name: z.string(),
});

export type OwnerOption = z.infer<typeof ownerOptionSchema>;

export const ownerOptionsResponseSchema = z.object({
    data: z.array(ownerOptionSchema),
});
