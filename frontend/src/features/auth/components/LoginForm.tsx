import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useNavigate } from '@tanstack/react-router';
import { loginSchema, type LoginInput } from '../schemas/auth';
import { useLogin } from '../api/useLogin';
import { isApiError } from '@/shared/lib/apiClient';

export function LoginForm() {
    const navigate = useNavigate();
    const login = useLogin();

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors, isSubmitting },
    } = useForm<LoginInput>({
        resolver: zodResolver(loginSchema),
        defaultValues: { email: '', password: '', remember: false },
    });

    const onSubmit = handleSubmit(async (values) => {
        try {
            await login.mutateAsync(values);
            navigate({ to: '/' });
        } catch (err) {
            // 14.3: map the backend's { message, errors, code } shape onto the
            // relevant fields, falling back to a form-level error.
            if (isApiError(err) && err.response?.status === 422) {
                setError('email', { message: err.response.data.errors?.email?.[0] ?? err.response.data.message });
            } else {
                setError('root', { message: 'Something went wrong. Please try again.' });
            }
        }
    });

    return (
        <form onSubmit={onSubmit} className="flex w-full max-w-sm flex-col gap-4" noValidate>
            <div className="flex flex-col gap-1.5">
                <label htmlFor="email" className="text-sm font-medium text-[var(--color-ink)]">
                    Email
                </label>
                <input
                    id="email"
                    type="email"
                    autoComplete="email"
                    {...register('email')}
                    className="rounded-md border border-[var(--color-border)] bg-white px-3 py-2 text-sm text-[var(--color-ink)] outline-none focus:border-[var(--color-accent)] focus:ring-2 focus:ring-[var(--color-accent)]/20"
                />
                {errors.email && <p className="text-sm text-[var(--color-danger)]">{errors.email.message}</p>}
            </div>

            <div className="flex flex-col gap-1.5">
                <label htmlFor="password" className="text-sm font-medium text-[var(--color-ink)]">
                    Password
                </label>
                <input
                    id="password"
                    type="password"
                    autoComplete="current-password"
                    {...register('password')}
                    className="rounded-md border border-[var(--color-border)] bg-white px-3 py-2 text-sm text-[var(--color-ink)] outline-none focus:border-[var(--color-accent)] focus:ring-2 focus:ring-[var(--color-accent)]/20"
                />
                {errors.password && <p className="text-sm text-[var(--color-danger)]">{errors.password.message}</p>}
            </div>

            <label className="flex items-center gap-2 text-sm text-[var(--color-ink-muted)]">
                <input type="checkbox" {...register('remember')} className="rounded border-[var(--color-border)]" />
                Keep me signed in
            </label>

            {errors.root && <p className="text-sm text-[var(--color-danger)]">{errors.root.message}</p>}

            <button
                type="submit"
                disabled={isSubmitting}
                className="mt-2 rounded-md bg-[var(--color-accent)] px-4 py-2 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-50"
            >
                {isSubmitting ? 'Signing in…' : 'Sign in'}
            </button>
        </form>
    );
}
