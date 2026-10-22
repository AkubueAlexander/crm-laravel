import { useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isApiError } from '@/shared/lib/apiClient';
import { useOwnerOptions } from '@/features/accounts/api/useOwnerOptions';
import {
    ACTIVITY_TYPES,
    activityFormSchema,
    isoToLocalInput,
    localInputToIso,
    type Activity,
    type ActivityFormValues,
    type ActivityPayload,
    type ActivityTargetType,
} from '../schemas/activity';
import { useCreateActivity, useUpdateActivity } from '../api/useActivityMutations';

type ActivityFormProps = {
    target: { type: ActivityTargetType; id: number };
    activity?: Activity; // present -> edit mode, absent -> create mode
    onSuccess: () => void;
    onCancel: () => void;
};

const KNOWN_FIELDS = ['type', 'subject', 'body', 'due_at', 'owner_id'] as const;
type KnownField = (typeof KNOWN_FIELDS)[number];

function normalize(value: string | null | undefined): string | null {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
}

export function ActivityForm({ target, activity, onSuccess, onCancel }: ActivityFormProps) {
    const isEdit = !!activity;

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
        setError,
    } = useForm<ActivityFormValues>({
        resolver: zodResolver(activityFormSchema),
        defaultValues: {
            type: activity?.type ?? 'task',
            subject: activity?.subject ?? '',
            body: activity?.body ?? '',
            due_at: activity?.due_at ? isoToLocalInput(activity.due_at) : '',
            owner_id: activity?.owner_id ?? null,
        },
    });

    const createActivity = useCreateActivity();
    const updateActivity = useUpdateActivity();
    const owners = useOwnerOptions();

    // Keep the current owner selectable even if missing from the options list.
    const ownerChoices = useMemo(() => {
        const list = owners.data ?? [];
        const current = activity?.owner;
        if (current && !list.some((o) => o.id === current.id)) return [current, ...list];
        return list;
    }, [owners.data, activity?.owner]);

    async function onSubmit(values: ActivityFormValues) {
        let dueAt: string | null = null;
        if (values.due_at) {
            dueAt = localInputToIso(values.due_at);
            if (dueAt === null) {
                setError('due_at', { message: 'Enter a valid date and time' });
                return;
            }
        }

        const payload: ActivityPayload = {
            type: values.type,
            subject: values.subject.trim(),
            body: normalize(values.body),
            due_at: dueAt,
        };

        try {
            if (isEdit && activity) {
                // Edit: null explicitly unassigns the owner.
                await updateActivity.mutateAsync({ id: activity.id, input: { ...payload, owner_id: values.owner_id ?? null } });
            } else {
                // Create: omit the owner so the backend defaults it to the acting user.
                await createActivity.mutateAsync({
                    ...payload,
                    ...(values.owner_id != null ? { owner_id: values.owner_id } : {}),
                    activityable_type: target.type,
                    activityable_id: target.id,
                });
            }
            onSuccess();
        } catch (err) {
            // 14.3: compose with the backend's { message, errors, code } shape.
            if (isApiError(err)) {
                const data = err.response?.data;
                let mapped = 0;
                const leftovers: string[] = [];
                for (const [field, messages] of Object.entries(data?.errors ?? {})) {
                    if ((KNOWN_FIELDS as readonly string[]).includes(field)) {
                        setError(field as KnownField, { message: messages[0] });
                        mapped++;
                    } else {
                        leftovers.push(messages[0]);
                    }
                }
                if (mapped === 0) {
                    setError('root.server', { message: leftovers[0] ?? data?.message ?? 'Could not save the activity.' });
                }
            } else {
                setError('root.server', { message: 'Something went wrong. Try again.' });
            }
        }
    }

    const isSaving = createActivity.isPending || updateActivity.isPending;

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-3 rounded-md border border-[var(--color-border)] p-3" noValidate>
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <label htmlFor="activity-type" className="block text-sm font-medium">Type</label>
                    <select id="activity-type" {...register('type')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm capitalize">
                        {ACTIVITY_TYPES.map((t) => (
                            <option key={t} value={t}>{t}</option>
                        ))}
                    </select>
                    {errors.type && <p className="mt-1 text-sm text-red-600">{errors.type.message}</p>}
                </div>

                <div>
                    <label htmlFor="activity-due" className="block text-sm font-medium">Due</label>
                    <input id="activity-due" type="datetime-local" {...register('due_at')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.due_at && <p className="mt-1 text-sm text-red-600">{errors.due_at.message}</p>}
                </div>
            </div>

            <div>
                <label htmlFor="activity-subject" className="block text-sm font-medium">Subject *</label>
                <input id="activity-subject" {...register('subject')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                {errors.subject && <p className="mt-1 text-sm text-red-600">{errors.subject.message}</p>}
            </div>

            <div>
                <label htmlFor="activity-body" className="block text-sm font-medium">Notes</label>
                <textarea id="activity-body" rows={3} {...register('body')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                {errors.body && <p className="mt-1 text-sm text-red-600">{errors.body.message}</p>}
            </div>

            <div>
                <label htmlFor="activity-owner" className="block text-sm font-medium">Owner</label>
                {owners.isPending ? (
                    <p className="mt-1 text-sm text-gray-500">Loading owners...</p>
                ) : owners.isError ? (
                    <p className="mt-1 text-sm text-gray-500">Owners unavailable. The current owner is kept.</p>
                ) : (
                    <select
                        id="activity-owner"
                        {...register('owner_id', { setValueAs: (v: string) => (v === '' ? null : Number(v)) })}
                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    >
                        <option value="">{isEdit ? 'Unassigned' : 'Me (default)'}</option>
                        {ownerChoices.map((o) => (
                            <option key={o.id} value={o.id}>{o.name}</option>
                        ))}
                    </select>
                )}
                {errors.owner_id && <p className="mt-1 text-sm text-red-600">{errors.owner_id.message}</p>}
            </div>

            {errors.root?.server && <p className="text-sm text-red-600">{errors.root.server.message}</p>}

            <div className="flex gap-2">
                <button
                    type="submit"
                    disabled={isSubmitting || isSaving}
                    className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
                >
                    {isEdit ? 'Save changes' : 'Add activity'}
                </button>
                <button type="button" onClick={onCancel} className="rounded-md border px-4 py-2 text-sm">
                    Cancel
                </button>
            </div>
        </form>
    );
}
