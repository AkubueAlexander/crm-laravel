import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isApiError } from '@/shared/lib/apiClient';
import { updateMatchSettingsSchema, type UpdateMatchSettingsInput } from '../schemas/matchSettings';
import { useContactMatchSettings, useUpdateContactMatchSettings } from '../api/useContactMatchSettings';

// 5.D: per-tenant duplicate-match threshold, range 1-100, default 75.
export function ContactMatchSettingsForm() {
    const { data, isPending: isLoading } = useContactMatchSettings();
    const updateSettings = useUpdateContactMatchSettings();
    const [savedMessage, setSavedMessage] = useState(false);

    const {
        register,
        handleSubmit,
        reset,
        formState: { errors, isSubmitting },
        setError,
    } = useForm<UpdateMatchSettingsInput>({
        resolver: zodResolver(updateMatchSettingsSchema),
        values: data ? { match_threshold: data.match_threshold } : undefined,
    });

    // Clear the "Saved" confirmation a couple seconds after showing it.
    useEffect(() => {
        if (!savedMessage) return;
        const t = setTimeout(() => setSavedMessage(false), 2000);
        return () => clearTimeout(t);
    }, [savedMessage]);

    async function onSubmit(values: UpdateMatchSettingsInput) {
        try {
            const saved = await updateSettings.mutateAsync(values);
            reset({ match_threshold: saved.match_threshold });
            setSavedMessage(true);
        } catch (err) {
            if (isApiError(err) && err.response?.data.errors) {
                for (const [field, messages] of Object.entries(err.response.data.errors)) {
                    setError(field as keyof UpdateMatchSettingsInput, { message: messages[0] });
                }
            }
        }
    }

    if (isLoading) {
        return <p className="text-sm text-gray-500">Loading{'\u2026'}</p>;
    }

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="max-w-sm space-y-4" noValidate>
            <div>
                <label htmlFor="match_threshold" className="block text-sm font-medium">
                    Duplicate match threshold
                </label>
                <p className="mt-1 text-xs text-gray-500">
                    Contacts scoring at or above this value are flagged as possible duplicates.
                    Default is {data?.default_threshold ?? 75}.
                </p>
                <input
                    id="match_threshold"
                    type="number"
                    min={1}
                    max={100}
                    {...register('match_threshold', { valueAsNumber: true })}
                    className="mt-2 w-32 rounded-md border px-3 py-2 text-sm"
                />
                {errors.match_threshold && (
                    <p className="mt-1 text-sm text-red-600">{errors.match_threshold.message}</p>
                )}
            </div>

            <div className="flex items-center gap-3">
                <button
                    type="submit"
                    disabled={isSubmitting || updateSettings.isPending}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    Save
                </button>
                {savedMessage && <span className="text-sm text-green-600">Saved</span>}
            </div>
        </form>
    );
}