import { memo } from 'react';
import { useFormContext, useWatch } from 'react-hook-form';
import type { SegmentField, SegmentFormValues } from '@/features/marketing/schemas/segmentForm';

type Props = {
  index: number;
  catalog: SegmentField[];
  count: number;
  onRemove: (index: number) => void;
  onMove: (from: number, to: number) => void;
};

const controlClass =
  'w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-2 py-1.5 text-sm text-[var(--color-ink)] disabled:opacity-60';
const iconButtonClass =
  'rounded-md border border-[var(--color-border)] px-2 py-1 text-xs text-[var(--color-ink-muted)] hover:bg-[var(--color-surface)] disabled:opacity-40';

function ConditionRowImpl({ index, catalog, count, onRemove, onMove }: Props) {
  const {
    register,
    setValue,
    control,
    formState: { errors },
  } = useFormContext<SegmentFormValues>();

  // Each row watches only its own field/operator, so typing in one row does not re-render the others.
  const field = useWatch({ control, name: `conditions.${index}.field` });
  const operator = useWatch({ control, name: `conditions.${index}.operator` });

  const fieldDef = catalog.find((f) => f.field === field);
  const operatorDef = fieldDef?.operators.find((o) => o.operator === operator);
  const kind = operatorDef?.value_kind ?? null;

  const fieldReg = register(`conditions.${index}.field`);
  const operatorReg = register(`conditions.${index}.operator`);
  const valueReg = register(`conditions.${index}.value`);

  const rowErrors = errors.conditions?.[index];
  const errorMessage =
    rowErrors?.value?.message ?? rowErrors?.operator?.message ?? rowErrors?.field?.message;

  return (
    <li className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3">
      <div className="grid gap-2 sm:grid-cols-[1fr_1fr_1.2fr_auto] sm:items-start">
        <select
          aria-label={`Condition ${index + 1} field`}
          className={controlClass}
          {...fieldReg}
          onChange={(event) => {
            void fieldReg.onChange(event);
            // New field: reset the operator to the first allowed one and clear the value.
            const next = catalog.find((f) => f.field === event.target.value);
            setValue(`conditions.${index}.operator`, next?.operators[0]?.operator ?? '', {
              shouldDirty: true,
            });
            setValue(`conditions.${index}.value`, '', { shouldDirty: true });
          }}
        >
          {catalog.map((f) => (
            <option key={f.field} value={f.field}>
              {f.label}
            </option>
          ))}
        </select>

        <select
          key={field}
          aria-label={`Condition ${index + 1} operator`}
          className={controlClass}
          {...operatorReg}
          onChange={(event) => {
            const newKind = fieldDef?.operators.find((o) => o.operator === event.target.value)
              ?.value_kind;
            void operatorReg.onChange(event);
            // Only clear the value when the kind of value changes.
            if (newKind !== kind) {
              setValue(`conditions.${index}.value`, '', { shouldDirty: true });
            }
          }}
        >
          {(fieldDef?.operators ?? []).map((o) => (
            <option key={o.operator} value={o.operator}>
              {o.label}
            </option>
          ))}
        </select>

        <div>
          {kind === 'none' ? (
            <p className="px-1 py-1.5 text-sm text-[var(--color-ink-muted)]">No value needed</p>
          ) : kind === 'date' ? (
            <input
              key="date"
              type="date"
              aria-label={`Condition ${index + 1} value`}
              className={controlClass}
              {...valueReg}
            />
          ) : kind === 'days' ? (
            <div className="flex items-center gap-2">
              <input
                key="days"
                type="number"
                inputMode="numeric"
                min={1}
                max={3650}
                aria-label={`Condition ${index + 1} value`}
                className={controlClass}
                {...valueReg}
              />
              <span className="text-sm text-[var(--color-ink-muted)]">days</span>
            </div>
          ) : (
            <input
              key="string"
              type="text"
              maxLength={100}
              aria-label={`Condition ${index + 1} value`}
              className={controlClass}
              {...valueReg}
            />
          )}
        </div>

        <div className="flex gap-1">
          <button
            type="button"
            className={iconButtonClass}
            aria-label={`Move condition ${index + 1} up`}
            disabled={index === 0}
            onClick={() => onMove(index, index - 1)}
          >
            Up
          </button>
          <button
            type="button"
            className={iconButtonClass}
            aria-label={`Move condition ${index + 1} down`}
            disabled={index === count - 1}
            onClick={() => onMove(index, index + 1)}
          >
            Down
          </button>
          <button
            type="button"
            className={iconButtonClass}
            aria-label={`Remove condition ${index + 1}`}
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

export const ConditionRow = memo(ConditionRowImpl);