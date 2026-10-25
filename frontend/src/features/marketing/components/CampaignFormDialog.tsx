import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isAxiosError } from 'axios';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useCreateCampaign } from '../api/useCreateCampaign';
import { useUpdateCampaign } from '../api/useUpdateCampaign';
import type { Campaign } from '../schemas/campaign';
import { campaignFormSchema, toCampaignInput, type CampaignFormValues } from '../schemas/campaignForm';

type Props = {
    // null = closed, 'new' = create, Campaign = edit.
    target: Campaign | 'new' | null;
    onClose: () => void;
};

// First message per field from a 422 body shaped { message, errors: { field: [msg, ...] }, code }.
function serverFieldErrors(error: unknown): Record<string, string> {
    if (!isAxiosError(error) || error.response?.status !== 422) return {};
    const errors = (error.response.data as { errors?: Record<string, unknown> } | undefined)?.errors;
    if (!errors || typeof errors !== 'object') return {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        const first = Array.isArray(messages) ? messages[0] : messages;
        if (typeof first === 'string') out[field] = first;
    }
    return out;
}

function CampaignForm({ campaign, onClose }: { campaign: Campaign | null; onClose: () => void }) {
    const create = useCreateCampaign();
    const update = useUpdateCampaign();
    const [formError, setFormError] = useState<string | null>(null);

    const {
        register,
        handleSubmit,
        setError,
        setFocus,
        formState: { errors, isSubmitting },
    } = useForm<CampaignFormValues>({
        resolver: zodResolver(campaignFormSchema),
        defaultValues: { name: campaign?.name ?? '', subject: campaign?.subject ?? '' },
    });

    useEffect(() => {
        setFocus('name');
    }, [setFocus]);

    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape' && !isSubmitting) onClose();
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [isSubmitting, onClose]);

    const onSubmit = handleSubmit(async (values) => {
        setFormError(null);
        const input = toCampaignInput(values);
        try {
            if (campaign) {
                await update.mutateAsync({ id: campaign.id, input });
            } else {
                await create.mutateAsync(input);
            }
            onClose();
        } catch (error) {
            const fields = serverFieldErrors(error);
            let handled = false;
            if (fields.name) {
                setError('name', { type: 'server', message: fields.name });
                handled = true;
            }
            if (fields.subject) {
                setError('subject', { type: 'server', message: fields.subject });
                handled = true;
            }
            if (fields.status) {
                setFormError(fields.status);
                handled = true;
            }
            if (!handled) setFormError(apiErrorMessage(error, 'Could not save the campaign. Please try again.'));
        }
    });

    const isEdit = campaign !== null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div
                role="dialog"
                aria-modal="true"
                aria-labelledby="campaign-form-title"
                className="w-full max-w-md rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-5 shadow-lg"
            >
                <h2 id="campaign-form-title" className="text-base font-medium text-[var(--color-ink)]">
                    {isEdit ? 'Edit campaign' : 'New campaign'}
                </h2>

                <form onSubmit={onSubmit} noValidate className="mt-4 flex flex-col gap-4">
                    <div>
                        <label htmlFor="campaign-name" className="mb-1 block text-sm font-medium">
                            Name
                        </label>
                        <input
                            id="campaign-name"
                            type="text"
                            autoComplete="off"
                            aria-invalid={errors.name ? true : undefined}
                            className="w-full rounded-md border px-3 py-2 text-sm"
                            {...register('name')}
                        />
                        {errors.name && (
                            <p role="alert" className="mt-1 text-xs text-red-700">
                                {errors.name.message}
                            </p>
                        )}
                    </div>

                    <div>
                        <label htmlFor="campaign-subject" className="mb-1 block text-sm font-medium">
                            Subject <span className="font-normal text-[var(--color-ink-muted)]">(optional)</span>
                        </label>
                        <input
                            id="campaign-subject"
                            type="text"
                            autoComplete="off"
                            aria-invalid={errors.subject ? true : undefined}
                            className="w-full rounded-md border px-3 py-2 text-sm"
                            {...register('subject')}
                        />
                        {errors.subject && (
                            <p role="alert" className="mt-1 text-xs text-red-700">
                                {errors.subject.message}
                            </p>
                        )}
                    </div>

                    {formError && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {formError}
                        </p>
                    )}

                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={isSubmitting}
                            className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                        >
                            {isSubmitting ? 'Saving...' : isEdit ? 'Save changes' : 'Create campaign'}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}

// The inner form is keyed by target so every open starts from fresh defaults (no reset() juggling).
export function CampaignFormDialog({ target, onClose }: Props) {
    if (target === null) return null;
    return (
        <CampaignForm
            key={target === 'new' ? 'new' : target.id}
            campaign={target === 'new' ? null : target}
            onClose={onClose}
        />
    );
}
