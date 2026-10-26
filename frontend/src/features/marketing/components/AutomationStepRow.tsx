import { memo } from 'react';
import { useFormContext } from 'react-hook-form';
import type { PickerOption } from '../api/useCampaignPickerOptions';
import { STEP_TYPE_DELAY, type AutomationFormValues } from '../schemas/automationForm';

type Props = {
    index: number;
    type: 'delay' | 'send_email';
    label: string;
    count: number;
    templates: PickerOption[];
    unitOptions: Array<{ value: string; label: string }>;
    onRemove: (index: number) => void;
    onMove: (from: number, to: number) => void;
};

type RowErrors = {
    amount?: { message?: string };
    unit?: { message?: string };
    email_template_id?: { message?: string };
};

const controlClass =
    'w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-2 py-1.5 text-sm text-[var(--color-ink)] disabled:opacity-60';
const iconButtonClass =
    'rounded-md border border-[var(--color-border)] px-2 py-1 text-xs text-[var(--color-ink-muted)] hover:bg-[var(--color-surface)] disabled:opacity-40';

function AutomationStepRowImpl({ index, type, label, count, templates, unitOptions, onRemove, onMove }: Props) {
    const {
        register,
        formState: { errors },
    } = useFormContext<AutomationFormValues>();

    // The row type never changes after the row is created, so it comes in as a prop: no watching, and typing in one
    // row does not re-render the others.
    const rowErrors = errors.steps?.[index] as RowErrors | undefined;
    const errorMessage = rowErrors?.amount?.message ?? rowErrors?.unit?.message ?? rowErrors?.email_template_id?.message;

    return (
        <li className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
            <div className="grid gap-2 sm:grid-cols-[auto_1fr_auto] sm:items-start">
                <div className="pt-1.5 text-sm font-medium text-[var(--color-ink)]">
                    {index + 1}. {label}
                </div>

                {type === STEP_TYPE_DELAY ? (
                    <div className="flex items-center gap-2">
                        <input
                            type="text"
                            inputMode="numeric"
                            aria-label={`Step ${index + 1} wait amount`}
                            className={`${controlClass} max-w-24`}
                            {...register(`steps.${index}.amount`)}
                        />
                        <select
                            aria-label={`Step ${index + 1} wait unit`}
                            className={`${controlClass} max-w-36`}
                            {...register(`steps.${index}.unit`)}
                        >
                            {unitOptions.map((o) => (
                                <option key={o.value} value={o.value}>
                                    {o.label}
                                </option>
                            ))}
                        </select>
                    </div>
                ) : (
                    <select
                        aria-label={`Step ${index + 1} email template`}
                        className={controlClass}
                        {...register(`steps.${index}.email_template_id`)}
                    >
                        <option value="">Choose a template...</option>
                        {templates.map((t) => (
                            <option key={t.id} value={String(t.id)}>
                                {t.name}
                            </option>
                        ))}
                    </select>
                )}

                <div className="flex gap-1">
                    <button
                        type="button"
                        className={iconButtonClass}
                        aria-label={`Move step ${index + 1} up`}
                        disabled={index === 0}
                        onClick={() => onMove(index, index - 1)}
                    >
                        Up
                    </button>
                    <button
                        type="button"
                        className={iconButtonClass}
                        aria-label={`Move step ${index + 1} down`}
                        disabled={index === count - 1}
                        onClick={() => onMove(index, index + 1)}
                    >
                        Down
                    </button>
                    <button
                        type="button"
                        className={iconButtonClass}
                        aria-label={`Remove step ${index + 1}`}
                        disabled={count <= 1}
                        onClick={() => onRemove(index)}
                    >
                        Remove
                    </button>
                </div>
            </div>
            {errorMessage ? (
                <p role="alert" className="mt-2 text-sm text-red-600">
                    {errorMessage}
                </p>
            ) : null}
        </li>
    );
}

export const AutomationStepRow = memo(AutomationStepRowImpl);