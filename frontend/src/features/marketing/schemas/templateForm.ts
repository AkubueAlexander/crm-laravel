import { z } from 'zod';

// ---- GET /email-templates/variables: the editor renders the variable picker and limits from this.

export const templateVariablesResponseSchema = z.object({
  data: z.array(z.object({ name: z.string(), label: z.string(), sample: z.string() })),
  limits: z.object({
    name: z.number().int().positive(),
    subject: z.number().int().positive(),
    body: z.number().int().positive(),
  }),
});
export type TemplateVariablesResponse = z.infer<typeof templateVariablesResponseSchema>;
export type TemplateLimits = TemplateVariablesResponse['limits'];

// ---- Form schema. UX layer only: the Laravel Form Request and the Actions are the boundary.

const VARIABLE_PATTERN = /\{\{\s*([A-Za-z0-9_]+)\s*\}\}/g;

export function usedVariables(text: string): string[] {
  const names = new Set<string>();
  for (const match of text.matchAll(VARIABLE_PATTERN)) {
    names.add(match[1]);
  }
  return [...names];
}

export function buildTemplateFormSchema(limits: TemplateLimits, variableNames: string[]) {
  const known = new Set(variableNames);

  return z
    .object({
      name: z
        .string()
        .trim()
        .min(1, 'Name is required')
        .max(limits.name, `Keep the name under ${limits.name} characters`),
      subject: z
        .string()
        .trim()
        .min(1, 'Subject is required')
        .max(limits.subject, `Keep the subject under ${limits.subject} characters`)
        .refine((value) => !/[\r\n]/.test(value), 'Subject must be a single line'),
      body: z
        .string()
        .refine((value) => value.trim() !== '', 'Body is required')
        .refine((value) => value.length <= limits.body, `Keep the body under ${limits.body} characters`),
    })
    .superRefine((data, ctx) => {
      for (const key of ['subject', 'body'] as const) {
        const unknown = usedVariables(data[key]).filter((name) => !known.has(name));
        if (unknown.length > 0) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: [key],
            message: `Unknown variable: ${unknown.map((n) => `{{ ${n} }}`).join(', ')}`,
          });
        }
      }
    });
}

export type TemplateFormValues = z.infer<ReturnType<typeof buildTemplateFormSchema>>;

export type TemplatePayload = { name: string; subject: string; body: string };

export function toTemplatePayload(values: TemplateFormValues): TemplatePayload {
  return { name: values.name.trim(), subject: values.subject.trim(), body: values.body };
}