import { z } from 'zod';
import type { Segment } from '@/features/marketing/schemas/segment';

// ---- Field catalog (GET /segments/fields). The UI renders from this; it never hardcodes fields/operators.

export const valueKindSchema = z.enum(['string', 'none', 'date', 'days']);
export type ValueKind = z.infer<typeof valueKindSchema>;

export const segmentFieldSchema = z.object({
  field: z.string(),
  label: z.string(),
  type: z.string(),
  operators: z.array(
    z.object({
      operator: z.string(),
      label: z.string(),
      value_kind: valueKindSchema,
    }),
  ),
});
export type SegmentField = z.infer<typeof segmentFieldSchema>;

export const segmentFieldsResponseSchema = z.object({
  data: z.array(segmentFieldSchema),
  max_conditions: z.number().int().positive(),
  match_modes: z.array(z.string()),
});
export type SegmentFieldsResponse = z.infer<typeof segmentFieldsResponseSchema>;

// ---- Per-row value validation (UX layer only: SegmentDsl::errors on the API is the boundary).

export function valueKindFor(
  catalog: SegmentField[],
  field: string,
  operator: string,
): ValueKind | null {
  const def = catalog.find((f) => f.field === field);
  const op = def?.operators.find((o) => o.operator === operator);
  return op?.value_kind ?? null;
}

export function validateConditionValue(kind: ValueKind, raw: string): string | null {
  const value = raw.trim();
  switch (kind) {
    case 'none':
      return null;
    case 'string': {
      if (value === '') return 'Enter a value';
      if (value.length > 100) return 'Keep it under 100 characters';
      return null;
    }
    case 'date': {
      const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
      if (!m) return 'Pick a date';
      const y = Number(m[1]);
      const mo = Number(m[2]);
      const d = Number(m[3]);
      const dt = new Date(Date.UTC(y, mo - 1, d));
      if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) {
        return 'Enter a real date';
      }
      return null;
    }
    case 'days': {
      if (!/^\d+$/.test(value)) return 'Enter a whole number of days';
      const n = Number(value);
      if (n < 1 || n > 3650) return 'Use between 1 and 3650 days';
      return null;
    }
  }
}

// ---- Form schema (built from the catalog so row rules follow the operator's value kind).

export function buildSegmentFormSchema(catalog: SegmentField[], maxConditions: number) {
  return z
    .object({
      name: z.string().trim().min(1, 'Name is required').max(160, 'Keep the name under 160 characters'),
      description: z.string().trim().max(500, 'Keep the description under 500 characters'),
      match_mode: z.enum(['all', 'any']),
      conditions: z
        .array(
          z.object({
            field: z.string().min(1, 'Choose a field'),
            operator: z.string().min(1, 'Choose an operator'),
            value: z.string(),
          }),
        )
        .min(1, 'Add at least one condition')
        .max(maxConditions, `Use at most ${maxConditions} conditions`),
    })
    .superRefine((data, ctx) => {
      data.conditions.forEach((row, index) => {
        const kind = valueKindFor(catalog, row.field, row.operator);
        if (kind === null) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['conditions', index, 'operator'],
            message: 'Choose an operator that fits this field',
          });
          return;
        }
        const message = validateConditionValue(kind, row.value);
        if (message) {
          ctx.addIssue({
            code: z.ZodIssueCode.custom,
            path: ['conditions', index, 'value'],
            message,
          });
        }
      });
    });
}

export type SegmentFormValues = z.infer<ReturnType<typeof buildSegmentFormSchema>>;
export type SegmentFormCondition = SegmentFormValues['conditions'][number];

// ---- Payload conversion.

export type SegmentPayloadCondition = {
  field: string;
  operator: string;
  value: string | number | null;
};

export type SegmentDefinition = {
  match_mode: 'all' | 'any';
  conditions: SegmentPayloadCondition[];
};

export type SegmentPayload = SegmentDefinition & {
  name: string;
  description: string | null;
};

function rowValue(kind: ValueKind | null, raw: string): string | number | null {
  const value = raw.trim();
  if (kind === 'none') return null;
  if (kind === 'days') return Number(value);
  return value;
}

export function toDefinition(
  matchMode: 'all' | 'any',
  conditions: SegmentFormCondition[],
  catalog: SegmentField[],
): SegmentDefinition {
  return {
    match_mode: matchMode,
    conditions: conditions.map((row) => ({
      field: row.field,
      operator: row.operator,
      value: rowValue(valueKindFor(catalog, row.field, row.operator), row.value),
    })),
  };
}

export function toPayload(values: SegmentFormValues, catalog: SegmentField[]): SegmentPayload {
  const description = values.description.trim();
  return {
    name: values.name.trim(),
    description: description === '' ? null : description,
    ...toDefinition(values.match_mode, values.conditions, catalog),
  };
}

/** Returns the definition only when every row validates; null otherwise (preview stays idle). */
export function definitionIfValid(
  matchMode: 'all' | 'any',
  conditions: SegmentFormCondition[],
  catalog: SegmentField[],
  maxConditions: number,
): SegmentDefinition | null {
  if (conditions.length < 1 || conditions.length > maxConditions) return null;
  for (const row of conditions) {
    const kind = valueKindFor(catalog, row.field, row.operator);
    if (kind === null) return null;
    if (validateConditionValue(kind, row.value) !== null) return null;
  }
  return toDefinition(matchMode, conditions, catalog);
}

// ---- API -> form.

export function emptyConditionRow(catalog: SegmentField[]): SegmentFormCondition {
  const first = catalog[0];
  return {
    field: first?.field ?? '',
    operator: first?.operators[0]?.operator ?? '',
    value: '',
  };
}

export function toFormValues(segment: Segment | null, catalog: SegmentField[]): SegmentFormValues {
  if (!segment) {
    return {
      name: '',
      description: '',
      match_mode: 'all',
      conditions: [emptyConditionRow(catalog)],
    };
  }
  return {
    name: segment.name,
    description: segment.description ?? '',
    match_mode: segment.match_mode === 'any' ? 'any' : 'all',
    conditions: segment.conditions.map((c) => ({
      field: c.field,
      operator: c.operator,
      value: c.value === null || c.value === undefined ? '' : String(c.value),
    })),
  };
}