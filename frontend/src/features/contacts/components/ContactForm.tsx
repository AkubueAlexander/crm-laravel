import { useRef, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isApiError } from '@/shared/lib/apiClient';
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

// 8.3: every mutating form uses RHF + Zod, no exceptions.
export function ContactForm({ contact, onSuccess }: ContactFormProps) {
    const isEdit = !!contact;

    const {
        register,
        handleSubmit,
        getValues,
        formState: { errors, isSubmitting },
        setError,
    } = useForm<CreateContactInput>({
        resolver: zodResolver(createContactSchema),
        defaultValues: {
            first_name: contact?.first_name ?? '',
            last_name: contact?.last_name ?? '',
            email: contact?.email ?? '',
            phone: contact?.phone ?? '',
            job_title: contact?.job_title ?? '',
        },
    });

    const createContact = useCreateContact();
    const updateContact = useUpdateContact(contact?.id ?? 0);
    const duplicateCheck = useDuplicateCheck();

    const [duplicates, setDuplicates] = useState<DuplicateMatch[]>([]);
    const [threshold, setThreshold] = useState<number>(75);
    const blurTimer = useRef<ReturnType<typeof setTimeout>>();

    function normalize(value: string | null | undefined): string | null {
        const trimmed = value?.trim();
        return trimmed ? trimmed : null;
    }

    // Reads the FULL current form state on every blur, not just the field that
    // fired -- the matching service scores combined signals (name + email +
    // phone together), so sending only the just-blurred field silently starves
    // it of context and produces weak or wrong matches.
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
        const payload = {
            first_name: normalize(values.first_name),
            last_name: values.last_name.trim(),
            email: normalize(values.email),
            phone: normalize(values.phone),
            job_title: normalize(values.job_title),
        };

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