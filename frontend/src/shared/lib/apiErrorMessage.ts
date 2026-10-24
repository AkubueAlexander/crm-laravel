import { ZodError } from 'zod';

// Turns whatever a failed request threw into one sentence for the user. Prefers the server's own
// validation message ({message, errors, code}, 14.3) over a generic one.
export function apiErrorMessage(
    error: unknown,
    fallback = 'Could not reach the server. Check your connection and try again.',
): string {
    if (error instanceof ZodError) {
        return 'The server sent an unexpected response. Please try again.';
    }

    if (typeof error === 'object' && error !== null && 'response' in error) {
        const data = (error as { response?: { data?: unknown } }).response?.data;

        if (data && typeof data === 'object') {
            const body = data as { message?: unknown; errors?: Record<string, unknown> };

            for (const value of Object.values(body.errors ?? {})) {
                const first = Array.isArray(value) ? value[0] : value;
                if (typeof first === 'string') return first;
            }

            if (typeof body.message === 'string' && body.message !== '') return body.message;
        }
    }

    return fallback;
}
