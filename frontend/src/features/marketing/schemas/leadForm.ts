import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

// Mirrors LeadFormResource::toArray() (app/Http/Resources/LeadFormResource.php).
export const leadFormFieldSchema = z.object({
  key: z.string(),
  label: z.string(),
  required: z.boolean(),
});

export const leadFormSchema = z.object({
  id: z.number(),
  uuid: z.string(),
  name: z.string(),
  is_active: z.boolean(),
  source: z.string().nullable(),
  success_message: z.string().nullable(),
  fields: z.array(leadFormFieldSchema),
  public_path: z.string(),
  created_at: z.string().nullable(),
  updated_at: z.string().nullable(),
});
export type LeadForm = z.infer<typeof leadFormSchema>;

export const leadFormsPageSchema = z
  .object({
    data: z.array(leadFormSchema),
    meta: paginationMetaSchema,
  })
  .passthrough();

// GET /lead-forms/catalog: the public lead fields a form may collect. The builder renders from it.
export const leadFormCatalogSchema = z.object({
  data: z.array(z.object({ key: z.string(), label: z.string() })),
  max_fields: z.number().int().positive(),
});
export type LeadFormCatalog = z.infer<typeof leadFormCatalogSchema>;
export type LeadFormCatalogItem = LeadFormCatalog['data'][number];

// ---- Builder form (UX layer only: SaveLeadFormAction + LeadFormDsl on the API are the boundary, 14.2).

export function buildLeadFormSchema(catalog: LeadFormCatalogItem[], maxFields: number) {
  const keys = catalog.map((c) => c.key);

  return z
    .object({
      name: z.string().trim().min(1, 'Name is required').max(160, 'Keep the name under 160 characters'),
      source: z.string().trim().max(100, 'Keep the source under 100 characters'),
      success_message: z.string().trim().max(300, 'Keep the message under 300 characters'),
      is_active: z.boolean(),
      fields: z
        .array(
          z.object({
            key: z.string().refine((k) => keys.includes(k), 'Choose a field'),
            label: z.string().trim().min(1, 'Enter a label').max(60, 'Keep the label under 60 characters'),
            required: z.boolean(),
          }),
        )
        .min(1, 'Add at least one field')
        .max(maxFields, `Use at most ${maxFields} fields`),
    })
    .superRefine((data, ctx) => {
      const seen = new Set<string>();
      data.fields.forEach((field, index) => {
        if (seen.has(field.key)) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['fields', index, 'key'],
            message: 'This field is already on the form',
          });
        }
        seen.add(field.key);
      });

      const first = data.fields.find((f) => f.key === 'first_name');
      if (!first || !first.required) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['fields'],
          message: 'First name must be on the form and required',
        });
      } else if (!data.fields.some((f) => f.key === 'email' || f.key === 'phone')) {
        ctx.addIssue({
          code: z.ZodIssueCode.custom,
          path: ['fields'],
          message: 'Add an email or phone field so you can reach the lead',
        });
      }
    });
}

export type LeadFormValues = z.infer<ReturnType<typeof buildLeadFormSchema>>;

export type LeadFormPayload = {
  name: string;
  is_active: boolean;
  source: string | null;
  success_message: string | null;
  fields: { key: string; label: string; required: boolean }[];
};

export function toLeadFormPayload(values: LeadFormValues): LeadFormPayload {
  const source = values.source.trim();
  const message = values.success_message.trim();
  return {
    name: values.name.trim(),
    is_active: values.is_active,
    source: source === '' ? null : source,
    success_message: message === '' ? null : message,
    fields: values.fields.map((f) => ({ key: f.key, label: f.label.trim(), required: f.required })),
  };
}

export function toLeadFormValues(form: LeadForm | null, catalog: LeadFormCatalogItem[]): LeadFormValues {
  if (form) {
    return {
      name: form.name,
      source: form.source ?? '',
      success_message: form.success_message ?? '',
      is_active: form.is_active,
      fields: form.fields.map((f) => ({ key: f.key, label: f.label, required: f.required })),
    };
  }

  const labelFor = (key: string) => catalog.find((c) => c.key === key)?.label ?? key;
  return {
    name: '',
    source: '',
    success_message: '',
    is_active: true,
    fields: [
      { key: 'first_name', label: labelFor('first_name'), required: true },
      { key: 'email', label: labelFor('email'), required: true },
    ],
  };
}