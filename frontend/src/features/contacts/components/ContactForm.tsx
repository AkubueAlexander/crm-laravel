import { useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isApiError } from '@/shared/lib/apiClient';
import { usePermission } from '@/shared/hooks/usePermission';
import { useAccountOptions } from '@/features/accounts/api/useAccountOptions';
import {
    createContactSchema,
    type Contact,
    type CreateContactInput,
    type DuplicateMatch,
} from '../schemas/contact';
import { useCreateContact, useUpdateContact } from '../api/useContactMutations';
import { useDuplicateCheck } from '../api/useDuplicateCheck';
import { DuplicateWarning } from './DuplicateWarning';

type ContactFormProps = {
    contact?: Contact; // present -> edit mode, absent -> create mode
    onSuccess?: (contact: Contact) => void;
};

// <select> values are strings; "" means "No account" -> null.
function toAccountId(value: unknown): number | null {
    if (value === '' || value === null || value === undefined) return null;
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

// 8.3: every mutating form uses RHF + Zod, no exceptions.
export function ContactForm({ contact, onSuccess }: ContactFormProps) {
    const isEdit = !!contact;

    // The options endpoint is gated by accounts.view. Without it the picker is hidden
    // and account_id is never sent, so an edit can never silently clear the link.
    const canPickAccount = usePermission('accounts.view');
    const accountOptions = useAccountOptions(canPickAccount);

    const {
        register,
        handleSubmit,
        getValues,
        formState: { errors, isSubmitting, dirtyFields },
        setError,
    } = useForm<CreateContactInput>({
        resolver: zodResolver(createContactSchema),
        defaultValues: {
            first_name: contact?.first_name ?? '',
            last_name: contact?.last_name ?? '',
            email: contact?.email ?? '',
            phone: contact?.phone ?? '',
            job_title: contact?.job_title ?? '',
            account_id: contact?.account_id ?? null,
        },
    });

    const createContact = useCreateContact();
    const updateContact = useUpdateContact(contact?.id ?? 0);
    const duplicateCheck = useDuplicateCheck();

    const [duplicates, setDuplicates] = useState<DuplicateMatch[]>([]);
    const [threshold, setThreshold] = useState<number>(75);
    const blurTimer = useRef<ReturnType<typeof setTimeout>>();

    // The contact's current account may be absent from the options (soft-deleted, or
    // beyond the 500-row cap). Keep it visible instead of letting the select go blank.
    const currentAccountId = contact?.account_id ?? null;
    const staleAccountId =
        accountOptions.isSuccess &&
        currentAccountId !== null &&
        !accountOptions.data.some((a) => a.id === currentAccountId)
            ? currentAccountId
            : null;

    function normalize(value: string | null | undefined): string | null {
        const trimmed = value?.trim();
        return trimmed ? trimmed : null;
    }

    // Reads the FULL current form state on every blur, not just the field that
    // fired -- the matching service scores combined signals (name + email +
    // phone together), so sending only the just-blurred field starves it of context.
    function runDuplicateCheck() {
        if (blurTimer.current) clearTimeout(blurTimer.current);
        blurTimer.current = setTimeout(() => {
            const values = getValues();
            duplicateCheck.mutate(
                {
                    first_name: normalize(values.first_name),
                    last_name: normalize(values.last_name),
                    email: normalize(values.email),
                    phone: normalize(values.phone),
                    exclude_contact_id: contact?.id ?? null,
                },
                {
                    onSuccess: (res) => {
                        setDuplicates(res.data);
                        setThreshold(res.threshold);
                    },
                },
            );
        }, 250);
    }

    async function onSubmit(values: CreateContactInput) {
        const payload: CreateContactInput = {
            first_name: normalize(values.first_name),
            last_name: values.last_name.trim(),
            email: normalize(values.email),
            phone: normalize(values.phone),
            job_title: normalize(values.job_title),
        };

        // account_id semantics: number assigns, null clears, omitted leaves untouched.
        // Create: send only when an account was chosen. Edit: send only when the user
        // actually changed the field (so a stale/unlisted account is never re-sent).
        if (canPickAccount) {
            const shouldSend = isEdit ? !!dirtyFields.account_id : values.account_id != null;
            if (shouldSend) payload.account_id = values.account_id ?? null;
        }

        try {
            const saved = isEdit
                ? await updateContact.mutateAsync(payload)
                : await createContact.mutateAsync(payload);
            onSuccess?.(saved);
        } catch (err) {
            // 14.3: compose with the backend's { message, errors, code } shape.
            if (isApiError(err) && err.response?.data.errors) {
                for (const [field, messages] of Object.entries(err.response.data.errors)) {
                    setError(field as keyof CreateContactInput, { message: messages[0] });
                }
            }
        }
    }

    const isSaving = createContact.isPending || updateContact.isPending;

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label htmlFor="first_name" className="block text-sm font-medium">First name</label>
                    <input
                        id="first_name"
                        {...register('first_name', {
                            onBlur: runDuplicateCheck,
                        })}
                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    />
                    {errors.first_name && <p className="mt-1 text-sm text-red-600">{errors.first_name.message}</p>}
                </div>

                <div>
                    <label htmlFor="last_name" className="block text-sm font-medium">Last name *</label>
                    <input
                        id="last_name"
                        {...register('last_name', {
                            onBlur: runDuplicateCheck,
                        })}
                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    />
                    {errors.last_name && <p className="mt-1 text-sm text-red-600">{errors.last_name.message}</p>}
                </div>
            </div>

            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label htmlFor="email" className="block text-sm font-medium">Email</label>
                    <input
                        id="email"
                        type="email"
                        {...register('email', {
                            onBlur: runDuplicateCheck,
                        })}
                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    />
                    {errors.email && <p className="mt-1 text-sm text-red-600">{errors.email.message}</p>}
                </div>

                <div>
                    <label htmlFor="phone" className="block text-sm font-medium">Phone</label>
                    <input
                        id="phone"
                        {...register('phone', {
                            onBlur: runDuplicateCheck,
                        })}
                        className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                    />
                    {errors.phone && <p className="mt-1 text-sm text-red-600">{errors.phone.message}</p>}
                </div>
            </div>

            <div>
                <label htmlFor="job_title" className="block text-sm font-medium">Job title</label>
                <input
                    id="job_title"
                    {...register('job_title')}
                    className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                />
                {errors.job_title && <p className="mt-1 text-sm text-red-600">{errors.job_title.message}</p>}
            </div>

            {canPickAccount && (
                <div>
                    <label htmlFor="account_id" className="block text-sm font-medium">Account</label>
                    {accountOptions.isSuccess ? (
                        // Mounted only after the options exist, so RHF applies the saved
                        // account_id to a select that already has its <option>s.
                        <select
                            id="account_id"
                            {...register('account_id', { setValueAs: toAccountId })}
                            className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="">No account</option>
                            {staleAccountId !== null && (
                                <option value={staleAccountId} disabled>
                                    Current account (not in list)
                                </option>
                            )}
                            {accountOptions.data.map((account) => (
                                <option key={account.id} value={account.id}>
                                    {account.name}
                                </option>
                            ))}
                        </select>
                    ) : (
                        <select
                            id="account_id"
                            disabled
                            className="mt-1 w-full rounded-md border px-3 py-2 text-sm"
                        >
                            <option>
                                {accountOptions.isError ? 'Could not load accounts' : 'Loading accounts...'}
                            </option>
                        </select>
                    )}
                    {errors.account_id && <p className="mt-1 text-sm text-red-600">{errors.account_id.message}</p>}
                </div>
            )}

            <DuplicateWarning matches={duplicates} threshold={threshold} />

            <button
                type="submit"
                disabled={isSubmitting || isSaving}
                className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-50"
            >
                {isEdit ? 'Save changes' : 'Create contact'}
            </button>
        </form>
    );
}