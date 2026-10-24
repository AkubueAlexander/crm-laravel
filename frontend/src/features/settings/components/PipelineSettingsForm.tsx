import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { usePipelineSettings, useUpdatePipelineSettings } from '../api/usePipelineSettings';
import {
    pipelineFormSchema,
    pipelineFormValues,
    pipelinePayload,
    toPercent,
    type PipelineFormInput,
    type PipelineSettings,
} from '../schemas/settings';

const secondaryButton = 'rounded-md border px-3 py-1.5 text-sm font-medium text-[var(--color-ink)] disabled:opacity-50';
const primaryButton = 'rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50';

export function PipelineSettingsForm() {
    const { data, isPending, isError } = usePipelineSettings();

    if (isPending) return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;

    if (isError || !data) {
        return (
            <p role="alert" className="text-sm text-red-600">
                Could not load the pipeline settings.
            </p>
        );
    }

    return <PipelineForm settings={data} />;
}

function PipelineForm({ settings }: { settings: PipelineSettings }) {
    const update = useUpdatePipelineSettings();

    // RHF + Zod (8.3). The server re-validates everything (14.2).
    const form = useForm<PipelineFormInput>({
        resolver: zodResolver(pipelineFormSchema),
        defaultValues: pipelineFormValues(settings),
    });

    const watched = form.watch('stages');

    const submit = form.handleSubmit((values) => {
        update.mutate(pipelinePayload(values), {
            // Re-baseline from what the server stored, so the form is no longer dirty.
            onSuccess: (saved) => form.reset(pipelineFormValues(saved)),
        });
    });

    const restoreDefaults = () => {
        for (const stage of settings.stages) {
            form.setValue(`stages.${stage.stage}`, toPercent(stage.default_probability), {
                shouldDirty: true,
                shouldValidate: true,
            });
        }
    };

    const thresholdPercent = toPercent(settings.commit_threshold);
    const { isDirty } = form.formState;

    return (
        <form onSubmit={submit} className="space-y-4" noValidate>
            <p className="text-sm text-[var(--color-ink-muted)]">
                The chance that a deal in each stage is won. The forecast multiplies deal amounts by these.
                Stages at {thresholdPercent}% or more count toward Commit; lower ones only toward Best case.
                Lost deals are not forecast.
            </p>

            <div className="space-y-3">
                {settings.stages.map((stage) => {
                    const value = watched?.[stage.stage];
                    const counts = Number.isFinite(value) && value / 100 >= settings.commit_threshold;
                    const error = form.formState.errors.stages?.[stage.stage]?.message;

                    return (
                        <div key={stage.stage} className="grid grid-cols-[8rem_7rem_1fr] items-start gap-3">
                            <label htmlFor={`stage-${stage.stage}`} className="pt-2 text-sm text-[var(--color-ink)]">
                                {stage.label}
                            </label>

                            <div>
                                <div className="flex items-center gap-1">
                                    <input
                                        id={`stage-${stage.stage}`}
                                        type="number"
                                        min={0}
                                        max={100}
                                        step={1}
                                        inputMode="numeric"
                                        aria-invalid={error ? true : undefined}
                                        {...form.register(`stages.${stage.stage}`, { valueAsNumber: true })}
                                        className="w-20 rounded-md border px-3 py-2 text-sm"
                                    />
                                    <span className="text-sm text-[var(--color-ink-muted)]">%</span>
                                </div>
                                {error && (
                                    <p role="alert" className="mt-1 text-xs text-red-600">
                                        {error}
                                    </p>
                                )}
                            </div>

                            <p className="pt-2 text-xs text-[var(--color-ink-muted)]">
                                Default {toPercent(stage.default_probability)}%
                                {' - '}
                                {counts ? 'counts toward Commit' : 'Best case only'}
                            </p>
                        </div>
                    );
                })}
            </div>

            {update.isError && (
                <p role="alert" className="text-sm text-red-600">
                    {apiErrorMessage(update.error)}
                </p>
            )}

            {update.isSuccess && !isDirty && (
                <p role="status" className="text-sm text-green-700">
                    Saved. The forecast now uses these probabilities.
                </p>
            )}

            <div className="flex justify-end gap-2">
                <button type="button" onClick={restoreDefaults} disabled={update.isPending} className={secondaryButton}>
                    Restore defaults
                </button>
                <button type="submit" disabled={!isDirty || update.isPending} className={primaryButton}>
                    {update.isPending ? 'Saving...' : 'Save pipeline'}
                </button>
            </div>
        </form>
    );
}
