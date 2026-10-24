import { type ReactNode } from 'react';
import axios from 'axios';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useQueryClient } from '@tanstack/react-query';
import { Link, useNavigate, useParams } from '@tanstack/react-router';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { isApiError } from '@/shared/lib/apiClient';
import { useAcceptInvitation, useInvitationPreview } from '../api/useAcceptInvitation';
import { acceptFormSchema, type AcceptFormValues } from '../schemas/invitation';

// 410 is the API's one answer for every unusable link; 404 means the token was malformed.
function isGone(error: unknown): boolean {
    return axios.isAxiosError(error) && [404, 410].includes(error.response?.status ?? 0);
}

function Card({ children }: { children: ReactNode }) {
    return (
        <div className="flex h-screen items-center justify-center bg-[var(--color-paper)] p-4">
            <div className="flex w-full max-w-sm flex-col gap-5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-8">
                {children}
            </div>
        </div>
    );
}

export function AcceptInvitationPage() {
    const { token } = useParams({ strict: false }) as unknown as { token?: string };
    const navigate = useNavigate();
    const queryClient = useQueryClient();
    const preview = useInvitationPreview(token);
    const accept = useAcceptInvitation();

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors },
    } = useForm<AcceptFormValues>({
        resolver: zodResolver(acceptFormSchema),
        defaultValues: { name: '', password: '', password_confirmation: '' },
    });

    if (preview.isPending) {
        return (
            <Card>
                <p className="text-sm text-[var(--color-ink-muted)]">Checking your invitation...</p>
            </Card>
        );
    }

    if (isGone(preview.error) || isGone(accept.error)) {
        return (
            <Card>
                <h1 className="text-lg font-medium text-[var(--color-ink)]">This link can't be used</h1>
                <p className="text-sm text-[var(--color-ink-muted)]">
                    The invitation link has expired, was already used, or was withdrawn. Ask an administrator to send you a new
                    one.
                </p>
                <Link to="/login" className="text-sm text-[var(--color-accent)] hover:underline">
                    Go to sign in
                </Link>
            </Card>
        );
    }

    if (preview.isError || !preview.data) {
        return (
            <Card>
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Couldn't load the invitation</h1>
                <p className="text-sm text-[var(--color-ink-muted)]">{apiErrorMessage(preview.error)}</p>
                <button
                    type="button"
                    onClick={() => void preview.refetch()}
                    className="self-start rounded-md border px-3 py-1.5 text-sm"
                >
                    Try again
                </button>
            </Card>
        );
    }

    const invitation = preview.data;

    const onSubmit = handleSubmit((values) => {
        if (!token) return;
        accept.mutate(
            { token, ...values },
            {
                onSuccess: () => {
                    // A new session now exists: drop everything cached (possibly another user's) so the app
                    // loads /me and the tenant data fresh instead of redirecting to /login on a stale error.
                    queryClient.clear();
                    void navigate({ to: '/' });
                },
                onError: (error) => {
                    if (isApiError(error) && error.response?.status === 422) {
                        const fieldErrors = error.response.data.errors ?? {};
                        for (const field of ['name', 'password'] as const) {
                            const message = fieldErrors[field]?.[0];
                            if (message) setError(field, { message });
                        }
                    }
                },
            },
        );
    });

    // Field errors are shown inline; anything else (network, 5xx, throttled) gets a banner.
    const showBanner =
        accept.isError &&
        !(isApiError(accept.error) && accept.error.response?.status === 422 && Object.keys(accept.error.response.data.errors ?? {}).length > 0);

    return (
        <Card>
            <div>
                <h1 className="text-lg font-medium text-[var(--color-ink)]">
                    Join {invitation.tenant_name ?? 'your team'}
                </h1>
                <p className="mt-1 text-sm text-[var(--color-ink-muted)]">
                    You've been invited as <strong>{invitation.role_label}</strong>. Choose a name and password to finish.
                </p>
            </div>

            <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
                <div className="flex flex-col gap-1 text-sm">
                    <span className="font-medium text-[var(--color-ink)]">Email</span>
                    <span className="rounded-md border bg-gray-50 px-3 py-2 text-sm text-[var(--color-ink-muted)]">
                        {invitation.email}
                    </span>
                </div>

                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium text-[var(--color-ink)]">Your name</span>
                    <input type="text" autoComplete="name" autoFocus {...register('name')} className="rounded-md border px-3 py-2 text-sm" />
                    {errors.name && <span className="text-xs text-red-600">{errors.name.message}</span>}
                </label>

                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium text-[var(--color-ink)]">Password</span>
                    <input
                        type="password"
                        autoComplete="new-password"
                        {...register('password')}
                        className="rounded-md border px-3 py-2 text-sm"
                    />
                    {errors.password && <span className="text-xs text-red-600">{errors.password.message}</span>}
                </label>

                <label className="flex flex-col gap-1 text-sm">
                    <span className="font-medium text-[var(--color-ink)]">Confirm password</span>
                    <input
                        type="password"
                        autoComplete="new-password"
                        {...register('password_confirmation')}
                        className="rounded-md border px-3 py-2 text-sm"
                    />
                    {errors.password_confirmation && (
                        <span className="text-xs text-red-600">{errors.password_confirmation.message}</span>
                    )}
                </label>

                {showBanner && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {apiErrorMessage(accept.error, 'Could not finish setting up your account. Please try again.')}
                    </p>
                )}

                <button
                    type="submit"
                    disabled={accept.isPending}
                    className="rounded-md bg-[var(--color-accent)] px-3 py-2 text-sm text-white hover:opacity-90 disabled:opacity-50"
                >
                    {accept.isPending ? 'Creating your account...' : 'Create account and sign in'}
                </button>
            </form>
        </Card>
    );
}
