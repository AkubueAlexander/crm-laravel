import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isApiError } from '@/shared/lib/apiClient';
import { createAccountSchema, type Account, type CreateAccountInput } from '../schemas/account';
import { useCreateAccount, useUpdateAccount } from '../api/useAccountMutations';

type AccountFormProps = {
    account?: Account; // present -> edit mode, absent -> create mode
    onSuccess?: (account: Account) => void;
};

function normalize(value: string | null | undefined): string | null {
    const trimmed = value?.trim();
    return trimmed ? trimmed : null;
}

// 8.3: every mutating form uses RHF + Zod, no exceptions.
// owner_id is deliberately not sent: PATCH only touches supplied fields, so editing
// never disturbs an existing owner. The owner select lands with its options endpoint.
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
        },
    });

    const createAccount = useCreateAccount();
    const updateAccount = useUpdateAccount(account?.id ?? 0);

    async function onSubmit(values: CreateAccountInput) {
        const payload = {
            name: values.name.trim(),
            industry: normalize(values.industry),
            website: normalize(values.website),
            phone: normalize(values.phone),
        };

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