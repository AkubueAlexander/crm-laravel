import { z } from 'zod';

// Mirrors SearchController (app/Http/Controllers/Api/Search). A group the actor
// lacks the *.view permission for is absent from `data`, hence optional.
export const SEARCH_GROUP_KEYS = ['leads', 'contacts', 'accounts', 'deals'] as const;
export type SearchGroupKey = (typeof SEARCH_GROUP_KEYS)[number];

export const searchItemSchema = z.object({
    id: z.number(),
    label: z.string(),
    sublabel: z.string().nullable(),
});

export type SearchItem = z.infer<typeof searchItemSchema>;

export const searchResponseSchema = z.object({
    data: z.object({
        leads: z.array(searchItemSchema).optional(),
        contacts: z.array(searchItemSchema).optional(),
        accounts: z.array(searchItemSchema).optional(),
        deals: z.array(searchItemSchema).optional(),
    }),
    query: z.string(),
});

export type SearchResponse = z.infer<typeof searchResponseSchema>;

export const SEARCH_MIN_LENGTH = 2;
