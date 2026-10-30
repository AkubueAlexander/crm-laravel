import type { FieldValues, Path, UseFormReturn } from 'react-hook-form';
import { isApiError } from '@/shared/lib/apiClient';

// 14.2: the Laravel Form Request / Action is the validation boundary; Zod is only fast UX feedback.
// When the server rejects a form with 422 { message, errors, code }, map each error onto its field so a
// rule that exists only server-side still shows under the right input. Returns true when at least one
// field error was set. Non-validation failures (403, 409, 500, network) are not field errors: the form
// keeps showing them as a banner via apiErrorMessage.
export function applyServerErrors<T extends FieldValues>(form: UseFormReturn<T>, error: unknown): boolean {
    if (!isApiError(error)) return false;

    const errors = error.response?.data.errors;
    if (!errors) return false;

    let mapped = false;
    for (const [field, messages] of Object.entries(errors)) {
        const message = Array.isArray(messages) ? messages[0] : undefined;
        if (typeof message !== 'string') continue;

        form.setError(field as Path<T>, { type: 'server', message });
        mapped = true;
    }

    return mapped;
}
