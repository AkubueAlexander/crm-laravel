import { useMemo } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isApiError } from '@/shared/lib/apiClient';
import { createAccountSchema, type Account, type CreateAccountInput } from '../schemas/account';
import { useCreateAccount, useUpdateAccount } from '../api/useAccountMutations';
import { useOwnerOptions } from '../api/useOwnerOptions';

type AccountFormProps = {
    account?: Account; // present -> edit mode, absent -> create mode
    onSuccess?: (account: Account) => void;
};

function normalize(value: string | null | undefined): string | null {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
}

// 8.3: every mutating form uses RHF + Zod, no exceptions.
export function AccountForm({ account, onSuccess }: AccountFormProps) {
    const isEdit = !!account;

    const {
        register,
        handleSubmit,
        formState: { errors, isSubmitting },
        setError,
    } = useForm<CreateAccountInput>({
        resolver: zodResolver(createAccountSchema),
        defaultValues: {
            name: account?.name ?? '',
            industry: account?.industry ?? '',
            website: account?.website ?? '',
            phone: account?.phone ?? '',
            // undefined (not null) when unowned: the field is then omitted from PATCH unless touched.
            owner_id: account?.owner_id ?? undefined,
        },
    });

    const createAccount = useCreateAccount();
    const updateAccount = useUpdateAccount(account?.id ?? 0);
    const owners = useOwnerOptions();

    // Keep the current owner selectable even if they are missing from the options list.
    const ownerChoices = useMemo(() => {
        const list = owners.data ?? [];
        const current = account?.owner;
        if (current && !list.some((o) => o.id === current.id)) return [current, ...list];
        return list;
    }, [owners.data, account?.owner]);

    async function onSubmit(values: CreateAccountInput) {
        const payload: CreateAccountInput = {
            name: values.name.trim(),
            industry: normalize(values.industry),
            website: normalize(values.website),
            phone: normalize(values.phone),
        };
        // Only send owner_id when there is a value to act on: a number assigns,
        // null clears ("Unassigned"), undefined leaves the existing owner untouched.
        if (values.owner_id !== undefined) payload.owner_id = values.owner_id;

        try {
            const saved = isEdit
                ? await updateAccount.mutateAsync(payload)
                : await createAccount.mutateAsync(payload);
            onSuccess?.(saved);
        } catch (err) {
            // 14.3: compose with the backend's { message, errors, code } shape.
            if (isApiError(err) && err.response?.data.errors) {
                for (const [field, messages] of Object.entries(err.response.data.errors)) {
                    setError(field as keyof CreateAccountInput, { message: messages[0] });
                }
            }
        }
    }

    const isSaving = createAccount.isPending || updateAccount.isPending;

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <div>
                <label htmlFor="name" className="block text-sm font-medium">Name *</label>
                <input id="name" {...register('name')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                {errors.name && <p className="mt-1 text-sm text-red-600">{errors.name.message}</p>}
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label htmlFor="industry" className="block text-sm font-medium">Industry</label>
                    <input id="industry" {...register('industry')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.industry && <p className="mt-1 text-sm text-red-600">{errors.industry.message}</p>}
                </div>

                <div>
                    <label htmlFor="phone" className="block text-sm font-medium">Phone</label>
                    <input id="phone" {...register('phone')} className="mt-1 w-full rounded-md border px-3 py-2 text-sm" />
                    {errors.phone && <p className="mt-1 text-sm text-red-600">{errors.phone.message}</p>}
                </div>
            </div>

            <div>
                <label htmlFor="website" className="block text-sm font-medium">Website</label>
                <input
                    id="website"
                    placeholder="https://example.com"
                    {...register('website')}
                    className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                />
                {errors.website && <p className="mt-1 text-sm text-red-600">{errors.website.message}</p>}
            </div>

            <div>
                <label htmlFor="owner_id" className="block text-sm font-medium">Owner</label>
                {/* The select mounts only once options exist, so RHF applies the default value
                    against real <option>s (a select registered before its options load would
                    show "Unassigned" while the form still holds the old owner id). */}
                {owners.isPending ? (
                    <p className="mt-1 text-sm text-gray-500">Loading owners...</p>
                ) : owners.isError ? (
                    <p className="mt-1 text-sm text-gray-500">Owners unavailable. The current owner is kept.</p>
                ) : (
                    <select
                        id="owner_id"
                        {...register('owner_id', {
                            setValueAs: (v: string) => (v === '' ? null : Number(v)),
                        })}
                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    >
                        <option value="">Unassigned</option>
                        {ownerChoices.map((o) => (
                            <option key={o.id} value={o.id}>
                                {o.name}
                            </option>
                        ))}
                    </select>
                )}
                {errors.owner_id && <p className="mt-1 text-sm text-red-600">{errors.owner_id.message}</p>}
            </div>

            <button
                type="submit"
                disabled={isSubmitting || isSaving}
                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {isEdit ? 'Save changes' : 'Create account'}
            </button>
        </form>
    );
}