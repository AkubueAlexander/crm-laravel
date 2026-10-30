import { isAxiosError } from 'axios';
import { apiClient } from '@/shared/lib/apiClient';

// 14.1 (frontend half): uncaught errors are reported to the API, which logs them with the same
// tenant_id / user_id / request_id context as every other backend log line. No third-party SDK.
export type ClientErrorSource = 'render' | 'window' | 'promise';

const recent = new Map<string, number>();
const DEDUPE_MS = 10_000;

export function reportClientError(error: unknown, source: ClientErrorSource, componentStack?: string): void {
    // API failures are already logged server-side; reporting them again would only add noise.
    if (isAxiosError(error)) return;

    const err = error instanceof Error ? error : new Error(typeof error === 'string' ? error : 'Unknown error');

    const key = source + ':' + err.message;
    const now = Date.now();
    const last = recent.get(key);
    if (last !== undefined && now - last < DEDUPE_MS) return;
    if (recent.size > 50) recent.clear();
    recent.set(key, now);

    void apiClient
        .post('/client-errors', {
            message: err.message.slice(0, 1000) || 'Unknown error',
            stack: err.stack?.slice(0, 8000),
            component_stack: componentStack?.slice(0, 4000),
            url: window.location.pathname.slice(0, 500),
            source,
        })
        .catch(() => undefined);
}

export function installGlobalErrorReporting(): void {
    window.addEventListener('error', (event) => {
        reportClientError(event.error ?? event.message, 'window');
    });
    window.addEventListener('unhandledrejection', (event) => {
        reportClientError(event.reason, 'promise');
    });
}
