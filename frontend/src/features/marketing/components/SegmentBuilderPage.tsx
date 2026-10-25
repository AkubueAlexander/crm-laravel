import { useCallback, useMemo, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useNavigate } from '@tanstack/react-router';
import { isAxiosError } from 'axios';
import { FormProvider, useFieldArray, useForm, useWatch } from 'react-hook-form';
import {
  apiErrorMessage,
  useDebouncedValue,
  usePermission,
} from '@/features/marketing/api/segmentDeps';
import { useSegment } from '@/features/marketing/api/useSegment';
import { useSegmentFields } from '@/features/marketing/api/useSegmentFields';
import { useSegmentPreview } from '@/features/marketing/api/useSegmentPreview';
import { useCreateSegment, useUpdateSegment } from '@/features/marketing/api/useSaveSegment';
import { ConditionRow } from '@/features/marketing/components/ConditionRow';
import type { Segment } from '@/features/marketing/schemas/segment';
import {
  buildSegmentFormSchema,
  definitionIfValid,
  emptyConditionRow,
  toFormValues,
  toPayload,
  type SegmentFieldsResponse,
  type SegmentFormValues,
} from '@/features/marketing/schemas/segmentForm';

const inputClass =
  'w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-3 py-2 text-sm text-[var(--color-ink)] disabled:opacity-60';

const MODE_LABELS: Record<string, string> = {
  all: 'Match all conditions (AND)',
  any: 'Match any condition (OR)',
};

type Props = { segmentId?: number };

export function SegmentBuilderPage({ segmentId }: Props) {
  const catalogQuery = useSegmentFields();
  const segmentQuery = useSegment(segmentId ?? null);
  const editing = segmentId !== undefined;

  if (catalogQuery.isPending || (editing && segmentQuery.isPending)) {
    return <p className="p-6 text-sm text-[var(--color-ink-muted)]">Loading...</p>;
  }
  if (catalogQuery.isError) {
    return (
      <p role="alert" className="p-6 text-sm text-red-600">
        {apiErrorMessage(catalogQuery.error, 'Could not load the segment fields.')}
      </p>
    );
  }
  if (editing && segmentQuery.isError) {
    return (
      <div className="space-y-3 p-6">
        <p role="alert" className="text-sm text-red-600">
          {apiErrorMessage(segmentQuery.error, 'Could not load this segment.')}
        </p>
        <Link to="/marketing/segments" className="text-sm underline">
          Back to segments
        </Link>
      </div>
    );
  }

  return (
    <SegmentBuilderForm
      key={segmentId ?? 'new'}
      segmentId={segmentId}
      segment={segmentQuery.data ?? null}
      catalog={catalogQuery.data}
    />
  );
}

type FormProps = {
  segmentId?: number;
  segment: Segment | null;
  catalog: SegmentFieldsResponse;
};

function SegmentBuilderForm({ segmentId, segment, catalog }: FormProps) {
  const navigate = useNavigate();
  const canManage = usePermission('marketing.manage');
  const editing = segmentId !== undefined;

  const createSegment = useCreateSegment();
  const updateSegment = useUpdateSegment(segmentId ?? 0);

  const [banner, setBanner] = useState<string | null>(null);
  const [conditionsError, setConditionsError] = useState<string | null>(null);

  const fields = catalog.data;
  const maxConditions = catalog.max_conditions;
  const schema = useMemo(() => buildSegmentFormSchema(fields, maxConditions), [fields, maxConditions]);

  const form = useForm<SegmentFormValues>({
    resolver: zodResolver(schema),
    defaultValues: toFormValues(segment, fields),
    mode: 'onBlur',
  });
  const {
    register,
    handleSubmit,
    control,
    setError,
    formState: { errors, isSubmitting },
  } = form;

  const { fields: rows, append, remove, move } = useFieldArray({ control, name: 'conditions' });
  const handleRemove = useCallback((index: number) => remove(index), [remove]);
  const handleMove = useCallback((from: number, to: number) => move(from, to), [move]);

  // Live preview: debounce the watched definition, then only query when every row validates.
  const matchMode = useWatch({ control, name: 'match_mode' });
  const watchedConditions = useWatch({ control, name: 'conditions' });
  const rawDefinition = useMemo(
    () => JSON.stringify({ matchMode, conditions: watchedConditions }),
    [matchMode, watchedConditions],
  );
  const debouncedRaw = useDebouncedValue(rawDefinition, 500);
  const definition = useMemo(() => {
    const parsed = JSON.parse(debouncedRaw) as {
      matchMode: 'all' | 'any';
      conditions: SegmentFormValues['conditions'];
    };
    return definitionIfValid(parsed.matchMode, parsed.conditions ?? [], fields, maxConditions);
  }, [debouncedRaw, fields, maxConditions]);
  const preview = useSegmentPreview(definition);

  function applyApiErrors(error: unknown) {
    setBanner(null);
    setConditionsError(null);

    if (isAxiosError(error) && error.response?.status === 422) {
      const body = error.response.data as { errors?: Record<string, string[]> } | undefined;
      const unmatched: string[] = [];
      let mapped = false;

      for (const [key, messages] of Object.entries(body?.errors ?? {})) {
        const message = messages[0];
        if (!message) continue;
        if (key === 'name' || key === 'description' || key === 'match_mode') {
          setError(key, { type: 'server', message });
          mapped = true;
        } else if (key === 'conditions') {
          setConditionsError(message);
          mapped = true;
        } else if (/^conditions\.\d+\.(field|operator|value)$/.test(key)) {
          setError(key as `conditions.${number}.value`, { type: 'server', message });
          mapped = true;
        } else {
          unmatched.push(message);
        }
      }

      if (unmatched.length > 0) {
        setBanner(unmatched.join(' '));
      } else if (!mapped) {
        setBanner(apiErrorMessage(error, 'The segment could not be saved.'));
      }
      return;
    }

    setBanner(apiErrorMessage(error, 'The segment could not be saved.'));
  }

  const onSubmit = handleSubmit(async (values) => {
    setBanner(null);
    setConditionsError(null);
    const payload = toPayload(values, fields);
    try {
      if (editing) {
        await updateSegment.mutateAsync(payload);
      } else {
        await createSegment.mutateAsync(payload);
      }
      await navigate({ to: '/marketing/segments' });
    } catch (error) {
      applyApiErrors(error);
    }
  });

  const rootConditionsMessage =
    conditionsError ??
    (errors.conditions as { root?: { message?: string }; message?: string } | undefined)?.root
      ?.message ??
    (errors.conditions as { message?: string } | undefined)?.message ??
    null;

  const heading = editing ? (canManage ? 'Edit segment' : 'Segment') : 'New segment';

  if (!editing && !canManage) {
    return (
      <div className="space-y-3 p-6">
        <p className="text-sm text-[var(--color-ink-muted)]">
          You do not have access to create segments.
        </p>
        <Link to="/marketing/segments" className="text-sm underline">
          Back to segments
        </Link>
      </div>
    );
  }

  return (
    <div className="mx-auto max-w-4xl space-y-6 p-6">
      <div className="flex items-center justify-between gap-4">
        <h1 className="text-xl font-semibold text-[var(--color-ink)]">{heading}</h1>
        <Link to="/marketing/segments" className="text-sm underline">
          Back to segments
        </Link>
      </div>

      {!canManage ? (
        <p className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3 text-sm text-[var(--color-ink-muted)]">
          You have read-only access to this segment.
        </p>
      ) : null}

      {banner ? (
        <div role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
          {banner}
        </div>
      ) : null}

      <FormProvider {...form}>
        <form onSubmit={onSubmit} noValidate className="space-y-6">
          <fieldset disabled={!canManage || isSubmitting} className="min-w-0 space-y-6">
            <div className="space-y-1">
              <label htmlFor="segment-name" className="text-sm font-medium text-[var(--color-ink)]">
                Name
              </label>
              <input id="segment-name" type="text" className={inputClass} {...register('name')} />
              {errors.name?.message ? (
                <p role="alert" className="text-sm text-red-600">
                  {errors.name.message}
                </p>
              ) : null}
            </div>

            <div className="space-y-1">
              <label htmlFor="segment-description" className="text-sm font-medium text-[var(--color-ink)]">
                Description <span className="text-[var(--color-ink-muted)]">(optional)</span>
              </label>
              <textarea
                id="segment-description"
                rows={2}
                className={inputClass}
                {...register('description')}
              />
              {errors.description?.message ? (
                <p role="alert" className="text-sm text-red-600">
                  {errors.description.message}
                </p>
              ) : null}
            </div>

            <div className="space-y-2">
              <p className="text-sm font-medium text-[var(--color-ink)]">Conditions</p>
              <div className="flex flex-wrap gap-4">
                {catalog.match_modes.map((mode) => (
                  <label key={mode} className="flex items-center gap-2 text-sm text-[var(--color-ink)]">
                    <input type="radio" value={mode} {...register('match_mode')} />
                    {MODE_LABELS[mode] ?? mode}
                  </label>
                ))}
              </div>
              {errors.match_mode?.message ? (
                <p role="alert" className="text-sm text-red-600">
                  {errors.match_mode.message}
                </p>
              ) : null}

              <ul className="space-y-2">
                {rows.map((row, index) => (
                  <ConditionRow
                    key={row.id}
                    index={index}
                    catalog={fields}
                    count={rows.length}
                    onRemove={handleRemove}
                    onMove={handleMove}
                  />
                ))}
              </ul>

              {rootConditionsMessage ? (
                <p role="alert" className="text-sm text-red-600">
                  {rootConditionsMessage}
                </p>
              ) : null}

              <div className="flex items-center gap-3">
                <button
                  type="button"
                  className="rounded-md border border-[var(--color-border)] px-3 py-1.5 text-sm text-[var(--color-ink)] hover:bg-[var(--color-surface)] disabled:opacity-40"
                  disabled={rows.length >= maxConditions}
                  onClick={() => append(emptyConditionRow(fields))}
                >
                  Add condition
                </button>
                <span className="text-xs text-[var(--color-ink-muted)]">
                  {rows.length} of {maxConditions}
                </span>
              </div>
            </div>
          </fieldset>

          <section
            aria-live="polite"
            className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-4"
          >
            <h2 className="text-sm font-medium text-[var(--color-ink)]">Live preview</h2>
            {definition === null ? (
              <p className="mt-1 text-sm text-[var(--color-ink-muted)]">
                Complete every condition to see how many contacts match.
              </p>
            ) : preview.isError ? (
              <p className="mt-1 text-sm text-[var(--color-ink-muted)]">Preview is unavailable right now.</p>
            ) : preview.data ? (
              <div className={preview.isFetching ? 'opacity-60' : undefined}>
                <p className="mt-1 text-sm text-[var(--color-ink)]">
                  {preview.data.count} {preview.data.count === 1 ? 'contact matches' : 'contacts match'}
                </p>
                {preview.data.sample.length > 0 ? (
                  <ul className="mt-2 space-y-0.5 text-sm text-[var(--color-ink-muted)]">
                    {preview.data.sample.map((contact) => (
                      <li key={contact.id}>
                        {contact.name ?? 'Unnamed'}
                        {contact.email ? ` - ${contact.email}` : ''}
                      </li>
                    ))}
                  </ul>
                ) : null}
              </div>
            ) : (
              <p className="mt-1 text-sm text-[var(--color-ink-muted)]">Counting...</p>
            )}
          </section>

          {canManage ? (
            <div className="flex items-center gap-3">
              <button
                type="submit"
                disabled={isSubmitting}
                className="rounded-md bg-[var(--color-ink)] px-4 py-2 text-sm font-medium text-[var(--color-paper)] disabled:opacity-50"
              >
                {isSubmitting ? 'Saving...' : editing ? 'Save changes' : 'Create segment'}
              </button>
              <Link to="/marketing/segments" className="text-sm underline">
                Cancel
              </Link>
            </div>
          ) : null}
        </form>
      </FormProvider>
    </div>
  );
}