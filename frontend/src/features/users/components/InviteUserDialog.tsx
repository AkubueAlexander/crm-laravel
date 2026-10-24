import { useEffect, useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { Modal } from '@/shared/components/Modal';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { isApiError } from '@/shared/lib/apiClient';
import { useUserRoles } from '../api/useUserRoles';
import { useCreateInvitation } from '../api/useInvitationMutations';
import { inviteFormSchema, type InviteFormValues } from '../schemas/invitation';

const DEFAULTS: InviteFormValues = { email: '', role: 'viewer' };

export function InviteUserDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
    const { data: roles = [] } = useUserRoles();
    const create = useCreateInvitation();
    const [done, setDone] = useState<{ email: string; reissued: boolean } | null>(null);
    const [formError, setFormError] = useState<string | null>(null);

    const {
        register,
        handleSubmit,
        reset,
        setError,
        watch,
        formState: { errors },
    } = useForm<InviteFormValues>({ resolver: zodResolver(inviteFormSchema), defaultValues: DEFAULTS });

    // The component stays mounted while closed, so start every opening from a clean form.
    useEffect(() => {
        if (open) {
            reset(DEFAULTS);
            setDone(null);
            setFormError(null);
            create.reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const onSubmit = handleSubmit((values) => {
        setFormError(null);
        create.mutate(values, {
            onSuccess: ({ invitation, reissued }) => setDone({ email: invitation.email, reissued }),
            onError: (error) => {
                if (isApiError(error) && error.response?.status === 422) {
                    const fieldErrors = error.response.data.errors ?? {};
                    let mapped = false;
                    for (const field of ['email', 'role'] as const) {
                        const message = fieldErrors[field]?.[0];
                        if (message) {
                            setError(field, { message });
                            mapped = true;
                        }
                    }
                    if (mapped) return;
                }
                setFormError(apiErrorMessage(error, 'Could not send the invitation. Please try again.'));
            },
        });
    });

    const selectedRole = roles.find((r) => r.name === watch('role'));

    return (
        <Modal open={open} onClose={create.isPending ? () => undefined : onClose} title="Invite user">
            {done ? (
                <div className="flex flex-col gap-4">
                    <p className="text-sm text-[var(--color-ink)]">
                        {done.reissued ? (
                            <>
                                <strong>{done.email}</strong> already had a pending invitation. We sent them a fresh link, and the
                                old one no longer works.
                            </>
                        ) : (
                            <>
                                We emailed an invitation to <strong>{done.email}</strong>. The link works once and expires in 7 days.
                            </>
                        )}
                    </p>
                    <div className="flex justify-end">
                        <button
                            type="button"
                            onClick={onClose}
                            className="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm text-white hover:opacity-90"
                        >
                            Done
                        </button>
                    </div>
                </div>
            ) : roles.length === 0 ? (
                <p className="text-sm text-[var(--color-ink-muted)]">Loading roles...</p>
            ) : (
                <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-[var(--color-ink)]">Email</span>
                        <input
                            type="email"
                            autoComplete="off"
                            autoFocus
                            {...register('email')}
                            className="rounded-md border px-3 py-2 text-sm"
                        />
                        {errors.email && <span className="text-xs text-red-600">{errors.email.message}</span>}
                    </label>

                    <label className="flex flex-col gap-1 text-sm">
                        <span className="font-medium text-[var(--color-ink)]">Role</span>
                        <select {...register('role')} className="rounded-md border px-3 py-2 text-sm">
                            {roles.map((r) => (
                                <option key={r.name} value={r.name}>
                                    {r.label}
                                </option>
                            ))}
                        </select>
                        {selectedRole && <span className="text-xs text-[var(--color-ink-muted)]">{selectedRole.description}</span>}
                        {errors.role && <span className="text-xs text-red-600">{errors.role.message}</span>}
                    </label>

                    {formError && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {formError}
                        </p>
                    )}

                    <div className="flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={onClose}
                            disabled={create.isPending}
                            className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50"
                        >
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={create.isPending}
                            className="rounded-md bg-[var(--color-accent)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                        >
                            {create.isPending ? 'Sending...' : 'Send invitation'}
                        </button>
                    </div>
                </form>
            )}
        </Modal>
    );
}
