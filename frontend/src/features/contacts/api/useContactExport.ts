import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ZodError } from 'zod';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    contactExportSchema,
    isExportTerminal,
    type ContactExport,
    type ContactListQuery,
    type ExportFormat,
} from '../schemas/contactExport';

// Tenant-namespaced (1.2) and under the 'contacts' prefix, like the import key.
export function contactExportKey(tenantId: string | number | undefined, exportId: number | null) {
    return ['contacts', tenantId, 'export', exportId] as const;
}

// Kept current by useContactExportLive (Reverb). The slow interval is only a safety net
// for a missed push while the export is still running; it stops as soon as it finishes.
export function useContactExport(exportId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: contactExportKey(tenant?.id, exportId),
        queryFn: async ({ signal }): Promise<ContactExport> => {
            const { data } = await apiClient.get(`/contacts/exports/${exportId}`, { signal });
            return contactExportSchema.parse(data);
        },
        enabled: !!tenant && exportId !== null,
        staleTime: 10_000,
        refetchInterval: (query) => {
            const status = query.state.data?.status;
            return status !== undefined && !isExportTerminal(status) ? 15_000 : false;
        },
    });
}

export type StartContactExportInput = ContactListQuery & { format: ExportFormat };

export function useStartContactExport() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (input: StartContactExportInput): Promise<ContactExport> => {
            const { data } = await apiClient.post('/contacts/exports', {
                format: input.format,
                q: input.q || undefined,
                sort: input.sort,
                direction: input.direction,
            });
            return contactExportSchema.parse(data);
        },
        onSuccess: (created) => {
            const key = contactExportKey(tenant?.id, created.id);
            queryClient.setQueryData(key, created);
            // The worker may already have finished before this response arrived.
            void queryClient.invalidateQueries({ queryKey: key });
        },
    });
}

export class ExportDownloadError extends Error {}

function filenameFrom(disposition: string | null): string | null {
    const match = disposition?.match(/filename="?([^";]+)"?/i);
    return match?.[1] ?? null;
}

async function readErrorMessage(response: Response): Promise<string> {
    try {
        const body: unknown = await response.json();
        if (body && typeof body === 'object' && 'message' in body && typeof body.message === 'string') {
            return body.message;
        }
    } catch {
        // not JSON; fall through
    }
    return 'The export could not be downloaded.';
}

// The signed link in the cache lives only ten minutes, so the export is re-read first to get a
// fresh one. The file is fetched (not navigated to) so an expired or refused link shows a message
// here instead of replacing the whole page with a JSON error.
export function useDownloadContactExport() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (exportId: number): Promise<void> => {
            const { data } = await apiClient.get(`/contacts/exports/${exportId}`);
            const fresh = contactExportSchema.parse(data);

            if (!fresh.download_url) {
                throw new ExportDownloadError('This export has expired. Start a new one.');
            }

            const response = await fetch(fresh.download_url, { credentials: 'same-origin' });

            if (!response.ok) {
                throw new ExportDownloadError(await readErrorMessage(response));
            }

            const blob = await response.blob();
            const name = filenameFrom(response.headers.get('Content-Disposition')) ?? `contacts.${fresh.format}`;
            const href = URL.createObjectURL(blob);

            const anchor = document.createElement('a');
            anchor.href = href;
            anchor.download = name;
            document.body.appendChild(anchor);
            anchor.click();
            anchor.remove();

            window.setTimeout(() => URL.revokeObjectURL(href), 10_000);
        },
        onError: (_error, exportId) => {
            // Re-read so an export that expired meanwhile shows as expired.
            void queryClient.invalidateQueries({ queryKey: contactExportKey(tenant?.id, exportId) });
        },
    });
}

// Prefers the server's own message (e.g. "nothing to export", "over the limit") over a generic one.
export function exportErrorMessage(error: unknown): string {
    if (error instanceof ZodError) {
        return 'The server sent an unexpected response. Please try again.';
    }

    if (error instanceof ExportDownloadError) {
        return error.message;
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

    return 'Could not reach the server. Check your connection and try again.';
}
