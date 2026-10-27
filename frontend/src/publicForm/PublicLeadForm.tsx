import { useMemo, useRef, useState, type ReactNode } from 'react';
import { useForm, type Resolver } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { useMutation, useQuery } from '@tanstack/react-query';
import { Turnstile } from './Turnstile';
import {
    PublicFormError,
    buildSchema,
    fetchDefinition,
    submitLeadForm,
    type FormValues,
    type PublicFormDefinition,
} from './publicFormApi';

const inputClass =
    'w-full rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-base text-[var(--color-ink)] outline-none focus:border-[var(--color-ink)] aria-[invalid=true]:border-[var(--color-danger)]';

const INPUT_TYPE: Record<string, string> = { email: 'email', phone: 'tel' };
const AUTOCOMPLETE: Record<string, string> = {
    first_name: 'given-name',
    last_name: 'family-name',
    email: 'email',
    phone: 'tel',
    company: 'organization',
};

function Shell({ children }: { children: ReactNode }) {
    return (
        <main className="mx-auto w-full max-w-md px-4 py-8">
            <div className="rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-6">
                {children}
            </div>
        </main>
    );
}

export function Unavailable({ message }: { message?: string }) {
    return (
        <Shell>
            <p role="alert" className="text-sm text-[var(--color-ink-muted)]">
                {message ?? 'This form is not available.'}
            </p>
        </Shell>
    );
}

export function PublicLeadForm({ uuid }: { uuid: string }) {
    const query = useQuery({
        queryKey: ['public-form', uuid],
        queryFn: () => fetchDefinition(uuid),
        retry: false,
        staleTime: 5 * 60_000,
        refetchOnWindowFocus: false,
    });

    if (query.isPending) {
        return (
            <Shell>
                <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>
            </Shell>
        );
    }

    if (query.isError) {
        const notFound = query.error instanceof PublicFormError && query.error.status === 404;
        return <Unavailable message={notFound ? undefined : 'We could not load this form. Please try again later.'} />;
    }

    return <FormBody uuid={uuid} definition={query.data} />;
}

function FormBody({ uuid, definition }: { uuid: string; definition: PublicFormDefinition }) {
    const schema = useMemo(() => buildSchema(definition.fields), [definition.fields]);
    const defaults = useMemo(
        () => Object.fromEntries(definition.fields.map((f) => [f.key, ''])) as FormValues,
        [definition.fields],
    );

    const {
        register,
        handleSubmit,
        setError,
        formState: { errors },
    } = useForm<FormValues>({
        resolver: zodResolver(schema) as unknown as Resolver<FormValues>,
        defaultValues: defaults,
    });

    const honeypot = useRef<HTMLInputElement>(null);
    const [banner, setBanner] = useState<string | null>(null);
    const [token, setToken] = useState<string | null>(null);
    const [resetKey, setResetKey] = useState(0);
    const [done, setDone] = useState<string | null>(null);

    const mutation = useMutation({
        mutationFn: (body: Record<string, string>) => submitLeadForm(uuid, body),
        onSuccess: (r) => setDone(r.message || 'Thank you! We will be in touch.'),
        onError: (error) => {
            // Captcha tokens are single-use: always reset after a failed attempt.
            setToken(null);
            setResetKey((k) => k + 1);

            if (!(error instanceof PublicFormError)) {
                setBanner('Something went wrong. Please try again.');
                return;
            }
            if (error.status === 422) {
                const rest: string[] = [];
                for (const [key, msgs] of Object.entries(error.errors)) {
                    const m = msgs[0] ?? error.message;
                    if (definition.fields.some((f) => f.key === key)) {
                        setError(key, { type: 'server', message: m });
                    } else {
                        rest.push(m);
                    }
                }
                const hasFieldErrors = Object.keys(error.errors).some((k) => definition.fields.some((f) => f.key === k));
                setBanner(rest.length > 0 ? rest.join(' ') : hasFieldErrors ? null : error.message);
                return;
            }
            if (error.status === 429) {
                setBanner('Too many attempts, try again in a minute.');
            } else if (error.status === 404) {
                setBanner('This form is no longer available.');
            } else if (error.status === 0) {
                setBanner('Network problem. Check your connection and try again.');
            } else {
                setBanner('Something went wrong. Please try again.');
            }
        },
    });

    const onSubmit = (values: FormValues) => {
        setBanner(null);
        if (definition.captcha.enabled && !token) {
            setBanner('Please complete the verification below.');
            return;
        }

        const body: Record<string, string> = {};
        for (const f of definition.fields) {
            const v = (values[f.key] ?? '').trim();
            if (v !== '') body[f.key] = v;
        }
        body.website = honeypot.current?.value ?? '';
        if (token) body.captcha_token = token;

        // 9.9: campaign attribution from the page URL (?utm_source=...&utm_medium=...&utm_campaign=...).
        const params = new URLSearchParams(window.location.search);
        for (const key of ['utm_source', 'utm_medium', 'utm_campaign']) {
            const value = (params.get(key) ?? '').trim();
            if (value !== '') body[key] = value.slice(0, 100);
        }

        mutation.mutate(body);
    };

    if (done !== null) {
        return (
            <Shell>
                <p role="status" className="text-base text-[var(--color-ink)]">
                    {done}
                </p>
            </Shell>
        );
    }

    return (
        <Shell>
            <h1 className="mb-4 text-lg font-medium text-[var(--color-ink)]">{definition.name}</h1>

            <form onSubmit={handleSubmit(onSubmit)} noValidate className="space-y-4">
                {banner && (
                    <p
                        role="alert"
                        className="rounded-md border border-[var(--color-danger)] px-3 py-2 text-sm text-[var(--color-danger)]"
                    >
                        {banner}
                    </p>
                )}

                {definition.fields.map((f) => {
                    const id = `field-${f.key}`;
                    const err = errors[f.key]?.message;
                    return (
                        <div key={f.key}>
                            <label htmlFor={id} className="mb-1 block text-sm font-medium text-[var(--color-ink)]">
                                {f.label}
                                {f.required && (
                                    <span aria-hidden="true" className="text-[var(--color-danger)]">
                                        {' *'}
                                    </span>
                                )}
                            </label>
                            <input
                                id={id}
                                type={INPUT_TYPE[f.key] ?? 'text'}
                                autoComplete={AUTOCOMPLETE[f.key] ?? 'off'}
                                aria-invalid={err ? true : undefined}
                                aria-describedby={err ? `${id}-error` : undefined}
                                className={inputClass}
                                {...register(f.key)}
                            />
                            {err && (
                                <p id={`${id}-error`} role="alert" className="mt-1 text-sm text-[var(--color-danger)]">
                                    {err}
                                </p>
                            )}
                        </div>
                    );
                })}

                {/* Honeypot: invisible to people, tempting to bots. Never register it with RHF. */}
                <div
                    aria-hidden="true"
                    style={{ position: 'absolute', left: '-10000px', width: 1, height: 1, overflow: 'hidden' }}
                >
                    <label>
                        Website
                        <input ref={honeypot} type="text" name="website" tabIndex={-1} autoComplete="off" defaultValue="" />
                    </label>
                </div>

                {definition.captcha.enabled && definition.captcha.site_key && (
                    <Turnstile siteKey={definition.captcha.site_key} onToken={setToken} resetKey={resetKey} />
                )}

                <button
                    type="submit"
                    disabled={mutation.isPending}
                    className="w-full rounded-md bg-[var(--color-ink)] px-4 py-2 text-base font-medium text-white disabled:opacity-60"
                >
                    {mutation.isPending ? 'Sending...' : 'Submit'}
                </button>
            </form>
        </Shell>
    );
}
