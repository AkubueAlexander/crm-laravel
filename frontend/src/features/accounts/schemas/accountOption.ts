import { z } from 'zod';

// Mirrors AccountOptionsController's JSON shape: { data: [{ id, name }] }.
export const accountOptionSchema = z.object({
    id: z.number(),
    name: z.string(),
});

export type AccountOption = z.infer<typeof accountOptionSchema>;

export const accountOptionsResponseSchema = z.object({
    data: z.array(accountOptionSchema),
});