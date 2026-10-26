import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// List rows never carry the body (up to 50k characters); the detail endpoint adds it.
export const emailTemplateSchema = z.object({
  id: z.number().int(),
  name: z.string(),
  subject: z.string(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});

export const emailTemplateDetailSchema = emailTemplateSchema.extend({
  body: z.string(),
});

export const emailTemplatesPageSchema = z.object({
  data: z.array(emailTemplateSchema),
  meta: paginationMetaSchema,
});

export type EmailTemplate = z.infer<typeof emailTemplateSchema>;
export type EmailTemplateDetail = z.infer<typeof emailTemplateDetailSchema>;

export const TEMPLATE_SORT_COLUMNS = ['name', 'subject', 'created_at', 'updated_at'] as const;
export type TemplateSortColumn = (typeof TEMPLATE_SORT_COLUMNS)[number];

export const TEMPLATE_DEFAULT_SORT: TemplateSortColumn = 'updated_at';
export const TEMPLATE_DEFAULT_DIRECTION = 'desc' as const;

export type TemplatesSearch = {
  page?: number;
  q?: string;
  sort?: TemplateSortColumn;
  direction?: 'asc' | 'desc';
};

/**
 * Route validateSearch (8.7). Tolerant of junk in the URL; defaults and empties are dropped so a
 * default view keeps a clean URL.
 */
export function validateTemplatesSearch(raw: Record<string, unknown>): TemplatesSearch {
  const out: TemplatesSearch = {};

  const page = Number(raw.page);
  if (Number.isInteger(page) && page > 1) out.page = page;

  if (typeof raw.q === 'string' && raw.q.trim() !== '') out.q = raw.q.trim();

  const sort = (TEMPLATE_SORT_COLUMNS as readonly unknown[]).includes(raw.sort)
    ? (raw.sort as TemplateSortColumn)
    : undefined;
  const direction = raw.direction === 'asc' || raw.direction === 'desc' ? raw.direction : undefined;

  const isDefault =
    (sort ?? TEMPLATE_DEFAULT_SORT) === TEMPLATE_DEFAULT_SORT &&
    (direction ?? TEMPLATE_DEFAULT_DIRECTION) === TEMPLATE_DEFAULT_DIRECTION;
  if (!isDefault) {
    if (sort) out.sort = sort;
    if (direction) out.direction = direction;
  }

  return out;
}