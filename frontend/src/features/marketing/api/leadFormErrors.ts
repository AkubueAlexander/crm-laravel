import { isAxiosError } from 'axios';

export type ParsedApiError = {
  message: string;
  errors: Record<string, string[]>;
  status: number | null;
};

const FALLBACK = 'Something went wrong. Try again.';

/** Reads the API's { message, errors, code } error shape (14.3) without assuming the error is an Axios error. */
export function parseApiError(error: unknown): ParsedApiError {
  if (!isAxiosError(error)) return { message: FALLBACK, errors: {}, status: null };

  const data = error.response?.data as { message?: unknown; errors?: unknown } | undefined;
  const errors: Record<string, string[]> = {};

  if (data && typeof data.errors === 'object' && data.errors !== null) {
    for (const [key, value] of Object.entries(data.errors)) {
      if (Array.isArray(value)) {
        errors[key] = value.filter((v): v is string => typeof v === 'string');
      }
    }
  }

  return {
    message: typeof data?.message === 'string' && data.message !== '' ? data.message : FALLBACK,
    errors,
    status: error.response?.status ?? null,
  };
}