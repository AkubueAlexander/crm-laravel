import { z } from 'zod';

export type PublicField = { key: string; label: string; required: boolean };

export type PublicFormDefinition = {
    name: string;
    fields: PublicField[];
    captcha: { enabled: boolean; site_key: string | null };
};

export type FormValues = Record<string, string>;

export class PublicFormError extends Error {
    status: number;
    errors: Record<string, string[]>;

    constructor(status: number, message: string, errors: Record<string, string[]> = {}) {
        super(message);
        this.status = status;
        this.errors = errors;
    }
}

async function request<T>(url: string, body?: Record<string, string>): Promise<T> {
    const init: RequestInit =
        body === undefined
            ? { method: 'GET', headers: { Accept: 'application/json' }, credentials: 'omit' }
            : {
                  method: 'POST',
                  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                  body: JSON.stringify(body),
                  credentials: 'omit',
              };

    let res: Response;
    try {
        res = await fetch(url, init);
    } catch {
        throw new PublicFormError(0, 'Network error.');
    }

    let data: unknown = null;
    try {
        data = await res.json();
    } catch {
        data = null;
    }

    if (!res.ok) {
        const b = (data ?? {}) as { message?: unknown; errors?: unknown };
        const errors =
            b.errors && typeof b.errors === 'object' ? (b.errors as Record<string, string[]>) : {};
        throw new PublicFormError(
            res.status,
            typeof b.message === 'string' ? b.message : 'Request failed.',
            errors,
        );
    }

    return data as T;
}

export function fetchDefinition(uuid: string): Promise<PublicFormDefinition> {
    return request<PublicFormDefinition>(`/api/public/forms/${uuid}`);
}

export function submitLeadForm(uuid: string, body: Record<string, string>): Promise<{ message: string }> {
    return request<{ message: string }>(`/api/public/forms/${uuid}`, body);
}

// UX layer only: mirrors LeadFormDsl::submissionRules. The server action is the real boundary.
const MAX: Record<string, number> = {
    first_name: 100,
    last_name: 100,
    email: 255,
    phone: 50,
    company: 255,
};
const PHONE_RE = /^[\d\s+().\-x#]+$/i;
const EMAIL = z.string().email();

export function buildSchema(fields: PublicField[]) {
    const shape: Record<string, z.ZodTypeAny> = {};

    for (const f of fields) {
        const max = MAX[f.key] ?? 255;
        shape[f.key] = z
            .string()
            .trim()
            .max(max, `Must be at most ${max} characters.`)
            .superRefine((v, ctx) => {
                if (v === '') {
                    if (f.required) {
                        ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'This field is required.' });
                    }
                    return;
                }
                if (f.key === 'email' && !EMAIL.safeParse(v).success) {
                    ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Enter a valid email address.' });
                }
                if (f.key === 'phone' && !PHONE_RE.test(v)) {
                    ctx.addIssue({ code: z.ZodIssueCode.custom, message: 'Enter a valid phone number.' });
                }
            });
    }

    return z.object(shape);
}
