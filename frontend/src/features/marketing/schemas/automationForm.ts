import { z } from 'zod';
import { DELAY_UNITS, type Automation, type DelayUnit } from './automation';

// ---- Catalog (GET /automations/catalog). The builder renders from this; trigger and step labels are never hardcoded.

export const catalogParamSchema = z.object({
    key: z.string(),
    label: z.string(),
    kind: z.enum(['integer', 'select', 'segment', 'template']),
    min: z.number().optional(),
    options: z.array(z.object({ value: z.string(), label: z.string() })).optional(),
});

export const automationCatalogSchema = z.object({
    data: z.object({
        triggers: z.array(z.object({ type: z.string(), label: z.string(), params: z.array(catalogParamSchema) })),
        steps: z.array(z.object({ type: z.string(), label: z.string(), params: z.array(catalogParamSchema) })),
        limits: z.object({
            max_steps: z.number().int().positive(),
            max_delay_days: z.number().int().positive(),
        }),
    }),
});

export type AutomationCatalogResponse = z.infer<typeof automationCatalogSchema>;
export type AutomationCatalog = AutomationCatalogResponse['data'];
export type CatalogTrigger = AutomationCatalog['triggers'][number];

export const UNIT_SECONDS: Record<DelayUnit, number> = { minutes: 60, hours: 3600, days: 86400 };

export const STEP_TYPE_DELAY = 'delay';
export const STEP_TYPE_EMAIL = 'send_email';

// ---- Per-row validation (UX layer only: AutomationDsl::errors on the API is the boundary).

export function validateDelayAmount(raw: string, unit: DelayUnit, maxDays: number): string | null {
    const value = raw.trim();
    if (!/^\d+$/.test(value)) return 'Enter a whole number';
    const n = Number(value);
    if (n < 1) return 'Enter at least 1';
    if (n * UNIT_SECONDS[unit] > maxDays * 86400) return `Up to ${maxDays} days in total`;
    return null;
}

export function triggerNeedsSegment(catalog: AutomationCatalog, triggerType: string): boolean {
    const trigger = catalog.triggers.find((t) => t.type === triggerType);
    return !!trigger && trigger.params.some((p) => p.kind === 'segment');
}

// ---- Form schema. Step rows are flat (no nested `config`) so the field paths stay short: steps.2.amount.

const delayRowSchema = z.object({
    type: z.literal(STEP_TYPE_DELAY),
    amount: z.string(),
    unit: z.enum(DELAY_UNITS),
});

const emailRowSchema = z.object({
    type: z.literal(STEP_TYPE_EMAIL),
    email_template_id: z.string(),
});

export const stepRowSchema = z.discriminatedUnion('type', [delayRowSchema, emailRowSchema]);

export function buildAutomationFormSchema(catalog: AutomationCatalog) {
    const { max_steps: maxSteps, max_delay_days: maxDays } = catalog.limits;

    return z
        .object({
            name: z.string().trim().min(1, 'Name is required').max(160, 'Keep the name under 160 characters'),
            description: z.string().trim().max(500, 'Keep the description under 500 characters'),
            trigger_type: z.string().min(1, 'Choose a trigger'),
            trigger_segment_id: z.string(),
            steps: z
                .array(stepRowSchema)
                .min(1, 'Add at least one step')
                .max(maxSteps, `Use at most ${maxSteps} steps`),
        })
        .superRefine((data, ctx) => {
            if (triggerNeedsSegment(catalog, data.trigger_type) && data.trigger_segment_id.trim() === '') {
                ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['trigger_segment_id'], message: 'Choose a segment' });
            }

            data.steps.forEach((row, index) => {
                if (row.type === STEP_TYPE_DELAY) {
                    const message = validateDelayAmount(row.amount, row.unit, maxDays);
                    if (message) ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['steps', index, 'amount'], message });
                } else if (row.email_template_id.trim() === '') {
                    ctx.addIssue({
                        code: z.ZodIssueCode.custom,
                        path: ['steps', index, 'email_template_id'],
                        message: 'Choose an email template',
                    });
                }
            });

            const last = data.steps[data.steps.length - 1];
            if (last && last.type === STEP_TYPE_DELAY) {
                ctx.addIssue({
                    code: z.ZodIssueCode.custom,
                    path: ['steps'],
                    message: 'A sequence cannot end with a wait: finish with an email.',
                });
            }
        });
}

export type AutomationFormValues = z.infer<ReturnType<typeof buildAutomationFormSchema>>;
export type StepRow = AutomationFormValues['steps'][number];

// ---- Payload conversion (what POST/PUT /automations expects).

export type AutomationPayloadStep =
    | { type: 'delay'; config: { amount: number; unit: DelayUnit } }
    | { type: 'send_email'; config: { email_template_id: number } };

export type AutomationPayload = {
    name: string;
    description: string | null;
    trigger_type: string;
    trigger_config: { segment_id?: number };
    steps: AutomationPayloadStep[];
};

export function toPayload(values: AutomationFormValues, catalog: AutomationCatalog): AutomationPayload {
    const description = values.description.trim();

    return {
        name: values.name.trim(),
        description: description === '' ? null : description,
        trigger_type: values.trigger_type,
        trigger_config: triggerNeedsSegment(catalog, values.trigger_type)
            ? { segment_id: Number(values.trigger_segment_id) }
            : {},
        steps: values.steps.map(
            (row): AutomationPayloadStep =>
                row.type === STEP_TYPE_DELAY
                    ? { type: 'delay', config: { amount: Number(row.amount.trim()), unit: row.unit } }
                    : { type: 'send_email', config: { email_template_id: Number(row.email_template_id) } },
        ),
    };
}

// ---- API -> form.

export function emptyStepRow(type: typeof STEP_TYPE_DELAY | typeof STEP_TYPE_EMAIL): StepRow {
    return type === STEP_TYPE_DELAY
        ? { type: STEP_TYPE_DELAY, amount: '1', unit: 'days' }
        : { type: STEP_TYPE_EMAIL, email_template_id: '' };
}

export function toFormValues(automation: Automation | null, catalog: AutomationCatalog): AutomationFormValues {
    if (!automation) {
        return {
            name: '',
            description: '',
            trigger_type: catalog.triggers[0]?.type ?? '',
            trigger_segment_id: '',
            steps: [emptyStepRow(STEP_TYPE_EMAIL)],
        };
    }

    return {
        name: automation.name,
        description: automation.description ?? '',
        trigger_type: automation.trigger_type,
        trigger_segment_id:
            automation.trigger_config.segment_id === null || automation.trigger_config.segment_id === undefined
                ? ''
                : String(automation.trigger_config.segment_id),
        steps: automation.steps.map(
            (step): StepRow =>
                step.type === 'delay'
                    ? { type: 'delay', amount: String(step.config.amount), unit: step.config.unit }
                    : { type: 'send_email', email_template_id: String(step.config.email_template_id) },
        ),
    };
}

// ---- API 422 keys -> form field paths (AutomationDsl reports steps.1.config.amount; the form uses steps.1.amount).

export function formPathForApiKey(key: string): string | null {
    if (key === 'name' || key === 'description' || key === 'trigger_type') return key;
    if (key === 'trigger_config.segment_id') return 'trigger_segment_id';

    const row = /^steps\.(\d+)\.config\.(amount|unit|email_template_id)$/.exec(key);
    if (row) return `steps.${row[1]}.${row[2]}`;

    return null;
}