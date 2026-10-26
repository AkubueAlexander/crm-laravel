import { useCallback, useMemo, useState } from 'react';
import { zodResolver } from '@hookform/resolvers/zod';
import { Link, useNavigate } from '@tanstack/react-router';
import { FormProvider, useFieldArray, useForm, useWatch } from 'react-hook-form';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { isApiError } from '@/shared/lib/apiClient';
import { useAutomation } from '../api/useAutomation';
import { useAutomationCatalog } from '../api/useAutomationCatalog';
import { useSegmentOptions, useTemplateOptions, type PickerOption } from '../api/useCampaignPickerOptions';
import { useCreateAutomation, useUpdateAutomation } from '../api/useSaveAutomation';
import type { Automation } from '../schemas/automation';
import {
    STEP_TYPE_DELAY,
    STEP_TYPE_EMAIL,
    buildAutomationFormSchema,
    emptyStepRow,
    formPathForApiKey,
    toFormValues,
    toPayload,
    triggerNeedsSegment,
    type AutomationCatalog,
    type AutomationFormValues,
} from '../schemas/automationForm';
import { AutomationStepRow } from './AutomationStepRow';

const inputClass =
    'w-full rounded-md border border-[var(--color-border)] bg-[var(--color-paper)] px-3 py-2 text-sm text-[var(--color-ink)] disabled:opacity-60';

type Props = { automationId?: number };

export function AutomationBuilderPage({ automationId }: Props) {
    const editing = automationId !== undefined;
    const catalogQuery = useAutomationCatalog();
    const automationQuery = useAutomation(automationId ?? null);
    const segmentsQuery = useSegmentOptions();
    const templatesQuery = useTemplateOptions();

    if (
        catalogQuery.isPending ||
        segmentsQuery.isPending ||
        templatesQuery.isPending ||
        (editing && automationQuery.isPending)
    ) {
        return <p className="p-6 text-sm text-[var(--color-ink-muted)]">Loading...</p>;
    }

    const loadError = catalogQuery.error ?? segmentsQuery.error ?? templatesQuery.error;
    if (catalogQuery.isError || segmentsQuery.isError || templatesQuery.isError) {
        return (
            <p role="alert" className="p-6 text-sm text-red-600">
                {apiErrorMessage(loadError, 'Could not load the automation builder.')}
            </p>
        );
    }

    if (editing && automationQuery.isError) {
        return (
            <div className="space-y-3 p-6">
                <p role="alert" className="text-sm text-red-600">
                    {apiErrorMessage(automationQuery.error, 'Could not load this automation.')}
                </p>
                <Link to="/marketing/automations" className="text-sm underline">
                    Back to automations
                </Link>
            </div>
        );
    }

    return (
        <AutomationBuilderForm
            // Remount when the server copy changes (a save bumps lock_version), so the form never shows stale defaults.
            key={`${automationId ?? 'new'}-${automationQuery.data?.lock_version ?? 0}`}
            automationId={automationId}
            automation={automationQuery.data ?? null}
            catalog={catalogQuery.data.data}
            segments={segmentsQuery.data}
            templates={templatesQuery.data}
        />
    );
}

type FormProps = {
    automationId?: number;
    automation: Automation | null;
    catalog: AutomationCatalog;
    segments: PickerOption[];
    templates: PickerOption[];
};

function AutomationBuilderForm({ automationId, automation, catalog, segments, templates }: FormProps) {
    const navigate = useNavigate();
    const canManage = usePermission('marketing.manage');
    const editing = automationId !== undefined;
    const locked = automation?.status === 'active';

    const createAutomation = useCreateAutomation();
    const updateAutomation = useUpdateAutomation(automationId ?? 0);

    const [banner, setBanner] = useState<string | null>(null);
    const [stepsError, setStepsError] = useState<string | null>(null);

    const maxSteps = catalog.limits.max_steps;
    const schema = useMemo(() => buildAutomationFormSchema(catalog), [catalog]);

    const form = useForm<AutomationFormValues>({
        resolver: zodResolver(schema),
        defaultValues: toFormValues(automation, catalog),
        mode: 'onBlur',
    });
    const {
        register,
        handleSubmit,
        control,
        setError,
        formState: { errors, isSubmitting },
    } = form;

    const { fields: rows, append, remove, move } = useFieldArray({ control, name: 'steps' });
    const handleRemove = useCallback((index: number) => remove(index), [remove]);
    const handleMove = useCallback((from: number, to: number) => move(from, to), [move]);

    const triggerType = useWatch({ control, name: 'trigger_type' });
    const needsSegment = triggerNeedsSegment(catalog, triggerType);

    const stepLabel = useCallback((type: string) => catalog.steps.find((s) => s.type === type)?.label ?? type, [catalog]);
    const unitOptions = useMemo(
        () =>
            catalog.steps.find((s) => s.type === STEP_TYPE_DELAY)?.params.find((p) => p.key === 'unit')?.options ?? [
                { value: 'minutes', label: 'Minutes' },
                { value: 'hours', label: 'Hours' },
                { value: 'days', label: 'Days' },
            ],
        [catalog],
    );
    const addable = catalog.steps.filter((s) => s.type === STEP_TYPE_DELAY || s.type === STEP_TYPE_EMAIL);

    function applyApiErrors(error: unknown) {
        setBanner(null);
        setStepsError(null);

        if (isApiError(error) && error.response?.status === 422) {
            const body = error.response.data as { errors?: Record<string, string[]> } | undefined;
            const unmatched: string[] = [];
            let mapped = false;

            for (const [key, messages] of Object.entries(body?.errors ?? {})) {
                const message = messages[0];
                if (!message) continue;
                const path = formPathForApiKey(key);
                if (path !== null) {
                    setError(path as Parameters<typeof setError>[0], { type: 'server', message });
                    mapped = true;
                } else if (key === 'steps') {
                    setStepsError(message);
                    mapped = true;
                } else {
                    unmatched.push(message);
                }
            }

            if (unmatched.length > 0) {
                setBanner(unmatched.join(' '));
            } else if (!mapped) {
                setBanner(apiErrorMessage(error, 'The automation could not be saved.'));
            }
            return;
        }

        setBanner(apiErrorMessage(error, 'The automation could not be saved.'));
    }

    const onSubmit = handleSubmit(async (values) => {
        setBanner(null);
        setStepsError(null);
        const payload = toPayload(values, catalog);
        try {
            if (editing) {
                await updateAutomation.mutateAsync(payload);
            } else {
                await createAutomation.mutateAsync(payload);
            }
            await navigate({ to: '/marketing/automations' });
        } catch (error) {
            applyApiErrors(error);
        }
    });

    const stepsErrorObj = errors.steps as { root?: { message?: string }; message?: string } | undefined;
    const rootStepsMessage = stepsError ?? stepsErrorObj?.root?.message ?? stepsErrorObj?.message ?? null;

    const heading = editing ? (canManage ? 'Edit automation' : 'Automation') : 'New automation';

    if (!editing && !canManage) {
        return (
            <div className="space-y-3 p-6">
                <p className="text-sm text-[var(--color-ink-muted)]">You do not have access to create automations.</p>
                <Link to="/marketing/automations" className="text-sm underline">
                    Back to automations
                </Link>
            </div>
        );
    }

    return (
        <div className="mx-auto max-w-4xl space-y-6 p-6">
            <div className="flex items-center justify-between gap-4">
                <h1 className="text-xl font-semibold text-[var(--color-ink)]">{heading}</h1>
                <Link to="/marketing/automations" className="text-sm underline">
                    Back to automations
                </Link>
            </div>

            {!canManage ? (
                <p className="rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] p-3 text-sm text-[var(--color-ink-muted)]">
                    You have read-only access to this automation.
                </p>
            ) : locked ? (
                <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                    This automation is active. Pause it from the list before editing.
                </p>
            ) : null}

            {banner ? (
                <div role="alert" className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700">
                    {banner}
                </div>
            ) : null}

            <FormProvider {...form}>
                <form onSubmit={onSubmit} noValidate className="space-y-6">
                    <fieldset disabled={!canManage || locked || isSubmitting} className="min-w-0 space-y-6">
                        <div className="space-y-1">
                            <label htmlFor="automation-name" className="text-sm font-medium text-[var(--color-ink)]">
                                Name
                            </label>
                            <input id="automation-name" type="text" className={inputClass} {...register('name')} />
                            {errors.name?.message ? (
                                <p role="alert" className="text-sm text-red-600">
                                    {errors.name.message}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-1">
                            <label htmlFor="automation-description" className="text-sm font-medium text-[var(--color-ink)]">
                                Description <span className="text-[var(--color-ink-muted)]">(optional)</span>
                            </label>
                            <textarea id="automation-description" rows={2} className={inputClass} {...register('description')} />
                            {errors.description?.message ? (
                                <p role="alert" className="text-sm text-red-600">
                                    {errors.description.message}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <p className="text-sm font-medium text-[var(--color-ink)]">Trigger</p>
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="space-y-1">
                                    <select aria-label="Trigger" className={inputClass} {...register('trigger_type')}>
                                        {catalog.triggers.map((t) => (
                                            <option key={t.type} value={t.type}>
                                                {t.label}
                                            </option>
                                        ))}
                                    </select>
                                    {errors.trigger_type?.message ? (
                                        <p role="alert" className="text-sm text-red-600">
                                            {errors.trigger_type.message}
                                        </p>
                                    ) : null}
                                </div>

                                {needsSegment ? (
                                    <div className="space-y-1">
                                        <select aria-label="Trigger segment" className={inputClass} {...register('trigger_segment_id')}>
                                            <option value="">Choose a segment...</option>
                                            {segments.map((s) => (
                                                <option key={s.id} value={String(s.id)}>
                                                    {s.name}
                                                </option>
                                            ))}
                                        </select>
                                        {errors.trigger_segment_id?.message ? (
                                            <p role="alert" className="text-sm text-red-600">
                                                {errors.trigger_segment_id.message}
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}
                            </div>
                        </div>

                        <div className="space-y-2">
                            <p className="text-sm font-medium text-[var(--color-ink)]">Steps</p>

                            <ol className="space-y-2">
                                {rows.map((row, index) => (
                                    <AutomationStepRow
                                        key={row.id}
                                        index={index}
                                        type={row.type}
                                        label={stepLabel(row.type)}
                                        count={rows.length}
                                        templates={templates}
                                        unitOptions={unitOptions}
                                        onRemove={handleRemove}
                                        onMove={handleMove}
                                    />
                                ))}
                            </ol>

                            {rootStepsMessage ? (
                                <p role="alert" className="text-sm text-red-600">
                                    {rootStepsMessage}
                                </p>
                            ) : null}

                            <div className="flex flex-wrap items-center gap-3">
                                {addable.map((s) => (
                                    <button
                                        key={s.type}
                                        type="button"
                                        className="rounded-md border border-[var(--color-border)] px-3 py-1.5 text-sm text-[var(--color-ink)] hover:bg-[var(--color-surface)] disabled:opacity-40"
                                        disabled={rows.length >= maxSteps}
                                        onClick={() => append(emptyStepRow(s.type === STEP_TYPE_DELAY ? STEP_TYPE_DELAY : STEP_TYPE_EMAIL))}
                                    >
                                        Add: {s.label}
                                    </button>
                                ))}
                                <span className="text-xs text-[var(--color-ink-muted)]">
                                    {rows.length} of {maxSteps}
                                </span>
                            </div>
                        </div>
                    </fieldset>

                    {canManage && !locked ? (
                        <div className="flex items-center gap-3">
                            <button
                                type="submit"
                                disabled={isSubmitting}
                                className="rounded-md bg-[var(--color-ink)] px-4 py-2 text-sm font-medium text-[var(--color-paper)] disabled:opacity-50"
                            >
                                {isSubmitting ? 'Saving...' : editing ? 'Save changes' : 'Create automation'}
                            </button>
                            <Link to="/marketing/automations" className="text-sm underline">
                                Cancel
                            </Link>
                        </div>
                    ) : null}
                </form>
            </FormProvider>
        </div>
    );
}