import { useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isApiError } from '@/shared/lib/apiClient';
import { useOwnerOptions } from '@/features/accounts/api/useOwnerOptions';
import { createLeadSchema, LEAD_ASSIGNABLE_STATUSES, type Lead, type CreateLeadInput } from '../schemas/lead';
import { useCreateLead, useUpdateLead } from '../api/useLeadMutations';

type LeadFormProps = {
    lead?: Lead; // present -> edit mode, absent -> create mode
    onSuccess?: (lead: Lead) => void;
};

function normalize(value: string | null | undefined): string | null {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
}


export function LeadForm({ lead, onSuccess }: LeadFormProps) {
    const isEdit = !!lead;
    const isConverted = lead?.status === 'converted';

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
        setError,
    } = useForm<CreateLeadInput>({
        resolver: zodResolver(createLeadSchema),
        defaultValues: {
            first_name: lead?.first_name ?? '',
            last_name: lead?.last_name ?? '',
            email: lead?.email ?? '',
            phone: lead?.phone ?? '',
            company: lead?.company ?? '',
            source: lead?.source ?? '',

            status: (lead && !isConverted ? lead.status : 'new') as CreateLeadInput['status'],
            owner_id: lead?.owner_id ?? undefined,
        },
    });

    const createLead = useCreateLead();
    const updateLead = useUpdateLead(lead?.id ?? 0);
    const owners = useOwnerOptions();

    const ownerChoices = useMemo(() => {
        const list = owners.data ?? [];
        const current = lead?.owner;
        if (current && !list.some((o) => o.id === current.id)) return [current, ...list];
        return list;
    }, [owners.data, lead?.owner]);

    async function onSubmit(values: CreateLeadInput) {
        const payload: CreateLeadInput = {
            first_name: values.first_name.trim(),
            last_name: normalize(values.last_name),
            email: normalize(values.email),
            phone: normalize(values.phone),
            company: normalize(values.company),
            source: normalize(values.source),
            status: values.status,
        };
        if (values.owner_id !== undefined) payload.owner_id = values.owner_id;

        try {
            const saved = isEdit
                ? await updateLead.mutateAsync(payload)
                : await createLead.mutateAsync(payload);
            onSuccess?.(saved);
        } catch (err) {

            if (isApiError(err) && err.response?.data.errors) {
                for (const [field, messages] of Object.entries(err.response.data.errors)) {
                    setError(field as keyof CreateLeadInput, { message: messages[0] });
                }
            }
        }
    }

    const isSaving = createLead.isPending || updateLead.isPending;

    if (isConverted) {
        return (
            <p className="text-sm text-[var(--color-ink-muted)]">
                This lead was converted{lead.converted_at ? ` on ${new Date(lead.converted_at).toLocaleDateString()}` : ''} and can no longer be edited here.
            </p>
        );
    }

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label htmlFor="first_name" className="block text-sm font-medium">First name *</label>
                    <input id="first_name" {...register('first_name')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.first_name && <p className="mt-1 text-sm text-red-600">{errors.first_name.message}</p>}
                </div>
                <div>
                    <label htmlFor="last_name" className="block text-sm font-medium">Last name</label>
                    <input id="last_name" {...register('last_name')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.last_name && <p className="mt-1 text-sm text-red-600">{errors.last_name.message}</p>}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label htmlFor="email" className="block text-sm font-medium">Email</label>
                    <input id="email" type="email" {...register('email')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email.message}</p>}
                </div>
                <div>
                    <label htmlFor="phone" className="block text-sm font-medium">Phone</label>
                    <input id="phone" {...register('phone')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.phone && <p className="mt-1 text-sm text-red-600">{errors.phone.message}</p>}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label htmlFor="company" className="block text-sm font-medium">Company</label>
                    <input id="company" {...register('company')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.company && <p className="mt-1 text-sm text-red-600">{errors.company.message}</p>}
                </div>
                <div>
                    <label htmlFor="source" className="block text-sm font-medium">Source</label>
                    <input id="source" {...register('source')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.source && <p className="mt-1 text-sm text-red-600">{errors.source.message}</p>}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label htmlFor="status" className="block text-sm font-medium">Status</label>
                    <select id="status" {...register('status')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm">
                        {LEAD_ASSIGNABLE_STATUSES.map((s) => (
                            <option key={s} value={s}>{s[0].toUpperCase() + s.slice(1)}</option>
                        ))}
                    </select>
                    {errors.status && <p className="mt-1 text-sm text-red-600">{errors.status.message}</p>}
                </div>

                <div>
                    <label htmlFor="owner_id" className="block text-sm font-medium">Owner</label>
                    {owners.isPending ? (
                        <p className="mt-1 text-sm text-gray-500">Loading owners...</p>
                    ) : owners.isError ? (
                        <p className="mt-1 text-sm text-gray-500">Owners unavailable. The current owner is kept.</p>
                    ) : (
                        <select
                            id="owner_id"
                            {...register('owner_id', { setValueAs: (v: string) => (v === '' ? null : Number(v)) })}
                            className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="">Unassigned</option>
                            {ownerChoices.map((o) => (
                                <option key={o.id} value={o.id}>{o.name}</option>
                            ))}
                        </select>
                    )}
                    {errors.owner_id && <p className="mt-1 text-sm text-red-600">{errors.owner_id.message}</p>}
                </div>
            </div>

            <button
                type="submit"
                disabled={isSubmitting || isSaving}
                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {isEdit ? 'Save changes' : 'Create lead'}
            </button>
        </form>
    );
}
