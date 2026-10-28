import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useQuoteSettings, useUpdateQuoteSettings } from '../api/useQuoteSettings';
import {
    quoteSettingsFormSchema,
    quoteSettingsFormValues,
    quoteSettingsPayload,
    type QuoteSettings,
    type QuoteSettingsFormInput,
} from '../schemas/quoteSettings';

const secondaryButton = 'rounded-md border px-3 py-1.5 text-sm font-medium text-[var(--color-ink)] disabled:opacity-50';
const primaryButton = 'rounded-md bg-indigo-600 px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50';

// 10.3c: the quote auto-approval threshold. Empty = every submitted quote waits for a human approver.
export function QuoteSettingsForm() {
    const { data, isPending, isError } = useQuoteSettings();

    if (isPending) return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;

    if (isError || !data) {
        return (
            <p role="alert" className="text-sm text-red-600">
                Could not load the quote settings.
            </p>
        );
    }

    return <QuoteForm settings={data} />;
}

function QuoteForm({ settings }: { settings: QuoteSettings }) {
    const update = useUpdateQuoteSettings();

    // RHF + Zod (8.3). The server re-validates everything (14.2).
    const form = useForm<QuoteSettingsFormInput>({
        resolver: zodResolver(quoteSettingsFormSchema),
        defaultValues: quoteSettingsFormValues(settings),
    });

    const submit = form.handleSubmit((values) => {
        update.mutate(quoteSettingsPayload(values), {
            // Re-baseline from what the server stored, so the form is no longer dirty.
            onSuccess: (saved) => form.reset(quoteSettingsFormValues(saved)),
        });
    });

    const { isDirty, errors } = form.formState;

    return (
        <form onSubmit={submit} className="space-y-4" noValidate>
            <p className="text-sm text-[var(--color-ink-muted)]">
                A quote whose total is at or below this amount is approved automatically when it is submitted. Leave it empty
                to require a human approver for every quote.
            </p>

            <div>
                <label htmlFor="quote-approval-threshold" className="mb-1 block text-sm font-medium text-[var(--color-ink)]">
                    Auto-approval threshold
                </label>
                <input
                    id="quote-approval-threshold"
                    type="text"
                    inputMode="decimal"
                    placeholder="No auto-approval"
                    aria-invalid={errors.approval_threshold ? true : undefined}
                    {...form.register('approval_threshold')}
                    className="w-48 rounded-md border px-3 py-2 text-sm"
                />
                {errors.approval_threshold && (
                    <p role="alert" className="mt-1 text-xs text-red-600">
                        {errors.approval_threshold.message}
                    </p>
                )}
            </div>

            {update.isError && (
                <p role="alert" className="text-sm text-red-600">
                    {apiErrorMessage(update.error)}
                </p>
            )}

            {update.isSuccess && !isDirty && (
                <p role="status" className="text-sm text-green-700">
                    Saved.
                </p>
            )}

            <div className="flex justify-end gap-2">
                <button
                    type="button"
                    onClick={() => form.setValue('approval_threshold', '', { shouldDirty: true, shouldValidate: true })}
                    disabled={update.isPending}
                    className={secondaryButton}
                >
                    Clear
                </button>
                <button type="submit" disabled={!isDirty || update.isPending} className={primaryButton}>
                    {update.isPending ? 'Saving...' : 'Save quote settings'}
                </button>
            </div>
        </form>
    );
}
