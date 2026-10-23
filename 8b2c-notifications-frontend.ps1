$ErrorActionPreference = 'Stop'
Write-Host 'crm-erp 8b.2c notifications frontend v1'

$Utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom {
    param([string]$Path, [string]$Content)
    $full = Join-Path (Get-Location).Path $Path
    $dir = Split-Path $full -Parent
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $useCrlf = $false
    if (Test-Path $full) {
        $existing = [System.IO.File]::ReadAllText($full)
        if ($existing.Contains("`r`n")) { $useCrlf = $true }
    }
    $text = $Content -replace "`r`n", "`n"
    $text = $text.TrimEnd("`n") + "`n"
    if ($useCrlf) { $text = $text -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $text, $Utf8NoBom)
    Write-Host "  wrote $Path"
}

function Patch-Once {
    param([string]$Path, [string]$Old, [string]$New, [string]$Marker)
    $full = Join-Path (Get-Location).Path $Path
    if (-not (Test-Path $full)) { throw "Patch target not found: $Path" }
    $raw = [System.IO.File]::ReadAllText($full)
    $crlf = $raw.Contains("`r`n")
    $norm = $raw -replace "`r`n", "`n"
    if ($norm.Contains($Marker)) { Write-Host "  skip $Path (already patched)"; return }
    $oldN = $Old -replace "`r`n", "`n"
    $newN = $New -replace "`r`n", "`n"
    $count = ([regex]::Matches($norm, [regex]::Escape($oldN))).Count
    if ($count -ne 1) { throw "Anchor in $Path matched $count times (expected 1): $oldN" }
    $norm = $norm.Replace($oldN, $newN)
    if ($crlf) { $norm = $norm -replace "`n", "`r`n" }
    [System.IO.File]::WriteAllText($full, $norm, $Utf8NoBom)
    Write-Host "  patched $Path"
}

foreach ($req in @('frontend\src\shared\components\AppShell.tsx', 'frontend\src\shared\hooks\useEchoChannel.ts', 'frontend\src\shared\hooks\useTenant.ts')) {
    if (-not (Test-Path $req)) { throw "Missing prerequisite: $req (run from the repo root)" }
}

$base = 'frontend\src\features\notifications'

# ---------------------------------------------------------------- 1. Schema
Write-NoBom "$base\schemas\notification.ts" @'
import { z } from 'zod';

// Mirrors NotificationResource (app/Http/Resources). tenant_id is never sent to the client.
// An empty PHP array serializes as [] rather than {}, so normalize it before parsing.
export const notificationDataSchema = z
    .object({
        subject: z.string().nullish(),
        due_at: z.string().nullish(),
        target_type: z.string().nullish(),
        target_id: z.number().nullish(),
        activity_id: z.number().nullish(),
        activity_type: z.string().nullish(),
    })
    .passthrough();

export const notificationSchema = z.object({
    id: z.string(),
    kind: z.string(),
    data: z.preprocess((value) => (Array.isArray(value) ? {} : value), notificationDataSchema),
    read_at: z.string().nullable(),
    created_at: z.string().nullable(),
});

export type AppNotification = z.infer<typeof notificationSchema>;

export const notificationsResponseSchema = z.object({
    data: z.array(notificationSchema),
    meta: z.object({
        current_page: z.number(),
        last_page: z.number(),
        per_page: z.number(),
        total: z.number(),
        unread_count: z.number(),
    }),
});

export type NotificationsResponse = z.infer<typeof notificationsResponseSchema>;

export const NOTIFICATIONS_PER_PAGE = 15;
export const OVERDUE_KIND = 'activity_overdue';
'@

# ---------------------------------------------------------------- 2. Query hook + scope
Write-NoBom "$base\api\useNotifications.ts" @'
import { useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import { NOTIFICATIONS_PER_PAGE, notificationsResponseSchema } from '../schemas/notification';

// The one place that builds the notifications query key. Namespaced by tenant (1.2)
// AND user, so neither a tenant switch nor an impersonation can show a stale inbox.
export function useNotificationsScope() {
    const { tenant } = useTenant();
    const { data: user } = useCurrentUser();

    return {
        key: ['notifications', tenant?.id, user?.id] as const,
        tenantId: tenant?.id,
        userId: user?.id,
        ready: tenant !== undefined && user !== undefined,
    };
}

// 8b.2: latest page only (the dropdown). Kept live by useNotificationsLive, not polling.
export function useNotifications() {
    const { key, ready } = useNotificationsScope();

    return useQuery({
        queryKey: key,
        queryFn: async ({ signal }) => {
            const res = await apiClient.get('/notifications', {
                params: { per_page: NOTIFICATIONS_PER_PAGE },
                signal,
            });
            return notificationsResponseSchema.parse(res.data);
        },
        enabled: ready,
        staleTime: 30_000,
    });
}
'@

# ---------------------------------------------------------------- 3. Mutations
Write-NoBom "$base\api\useNotificationMutations.ts" @'
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import type { NotificationsResponse } from '../schemas/notification';
import { useNotificationsScope } from './useNotifications';

// Both mutations patch the cached inbox optimistically, roll back on error, and
// always reconcile with the server afterwards (the same shape as useMoveDealStage, 3.2).

export function useMarkNotificationRead() {
    const queryClient = useQueryClient();
    const { key } = useNotificationsScope();

    return useMutation({
        mutationFn: async (id: string) => {
            await apiClient.post(`/notifications/${id}/read`);
        },
        onMutate: async (id) => {
            await queryClient.cancelQueries({ queryKey: key });
            const previous = queryClient.getQueryData<NotificationsResponse>(key);

            const target = previous?.data.find((n) => n.id === id);
            if (previous && target && !target.read_at) {
                queryClient.setQueryData<NotificationsResponse>(key, {
                    data: previous.data.map((n) => (n.id === id ? { ...n, read_at: new Date().toISOString() } : n)),
                    meta: { ...previous.meta, unread_count: Math.max(previous.meta.unread_count - 1, 0) },
                });
            }

            return { previous };
        },
        onError: (_error, _id, context) => {
            if (context?.previous) queryClient.setQueryData(key, context.previous);
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: key }),
    });
}

export function useMarkAllNotificationsRead() {
    const queryClient = useQueryClient();
    const { key } = useNotificationsScope();

    return useMutation({
        mutationFn: async () => {
            await apiClient.post('/notifications/read-all');
        },
        onMutate: async () => {
            await queryClient.cancelQueries({ queryKey: key });
            const previous = queryClient.getQueryData<NotificationsResponse>(key);

            if (previous) {
                const now = new Date().toISOString();
                queryClient.setQueryData<NotificationsResponse>(key, {
                    data: previous.data.map((n) => (n.read_at ? n : { ...n, read_at: now })),
                    meta: { ...previous.meta, unread_count: 0 },
                });
            }

            return { previous };
        },
        onError: (_error, _vars, context) => {
            if (context?.previous) queryClient.setQueryData(key, context.previous);
        },
        onSettled: () => queryClient.invalidateQueries({ queryKey: key }),
    });
}
'@

# ---------------------------------------------------------------- 4. Live hook (6.4)
Write-NoBom "$base\api\useNotificationsLive.ts" @'
import { useQueryClient } from '@tanstack/react-query';
import { useEchoChannel } from '@/shared/hooks/useEchoChannel';
import { useNotificationsScope } from './useNotifications';

// Laravel broadcasts database notifications as BroadcastNotificationCreated. The leading
// dot tells Echo not to prefix its App.Events namespace onto the event name.
const NOTIFICATION_EVENT = '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated';

// 6.4: the pushed event is only an "invalidate" signal. The inbox itself is always
// re-read through the API, so the client never trusts or stores the pushed payload.
export function useNotificationsLive() {
    const queryClient = useQueryClient();
    const { key, tenantId, userId } = useNotificationsScope();

    // Same name the server authorizes in UserChannel (1.5). Empty until both ids are known.
    const channel = tenantId !== undefined && userId !== undefined ? `tenant.${tenantId}.user.${userId}` : '';

    useEchoChannel(channel, NOTIFICATION_EVENT, () => {
        void queryClient.invalidateQueries({ queryKey: key });
    });
}
'@

# ---------------------------------------------------------------- 5. Bell
Write-NoBom "$base\components\NotificationBell.tsx" @'
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useNotifications } from '../api/useNotifications';
import { useNotificationsLive } from '../api/useNotificationsLive';
import { useMarkAllNotificationsRead, useMarkNotificationRead } from '../api/useNotificationMutations';
import { OVERDUE_KIND, type AppNotification } from '../schemas/notification';

// There are no record detail routes yet, so a click opens the target's section list
// (same trade-off as global search; deep links arrive with 8.7).
const TARGET_ROUTES = {
    lead: '/leads',
    contact: '/contacts',
    account: '/accounts',
    deal: '/deals',
} as const;

type TargetRoute = (typeof TARGET_ROUTES)[keyof typeof TARGET_ROUTES];

function routeFor(n: AppNotification): TargetRoute | null {
    const type = n.data.target_type;
    if (typeof type === 'string' && type in TARGET_ROUTES) {
        return TARGET_ROUTES[type as keyof typeof TARGET_ROUTES];
    }
    return null;
}

function formatWhen(iso: string): string {
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString();
}

function describeNotification(n: AppNotification): { title: string; detail: string | null } {
    if (n.kind === OVERDUE_KIND) {
        return {
            title: `Overdue: ${n.data.subject ?? 'Activity'}`,
            detail: n.data.due_at ? `Due ${formatWhen(n.data.due_at)}` : null,
        };
    }
    return { title: 'New notification', detail: n.created_at ? formatWhen(n.created_at) : null };
}

// Dropdown open state is purely visual, so it stays local useState (8.1). Everything
// else (list, unread count) comes from the TanStack Query cache.
export function NotificationBell() {
    useNotificationsLive();

    const { data, isPending, isError } = useNotifications();
    const markRead = useMarkNotificationRead();
    const markAll = useMarkAllNotificationsRead();
    const navigate = useNavigate();

    const [open, setOpen] = useState(false);
    const rootRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!open) return;

        function onMouseDown(e: MouseEvent) {
            if (rootRef.current && !rootRef.current.contains(e.target as Node)) setOpen(false);
        }
        function onKeyDown(e: KeyboardEvent) {
            if (e.key === 'Escape') setOpen(false);
        }

        document.addEventListener('mousedown', onMouseDown);
        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('mousedown', onMouseDown);
            document.removeEventListener('keydown', onKeyDown);
        };
    }, [open]);

    const unread = data?.meta.unread_count ?? 0;
    const items = data?.data ?? [];

    function choose(n: AppNotification) {
        if (!n.read_at) markRead.mutate(n.id);
        setOpen(false);
        const to = routeFor(n);
        if (to) void navigate({ to });
    }

    let body;
    if (isPending) {
        body = <p className="px-4 py-6 text-center text-sm text-[var(--color-ink-muted)]">Loading...</p>;
    } else if (isError) {
        body = <p className="px-4 py-6 text-center text-sm text-red-600">Could not load notifications.</p>;
    } else if (items.length === 0) {
        body = <p className="px-4 py-6 text-center text-sm text-[var(--color-ink-muted)]">You are all caught up.</p>;
    } else {
        body = (
            <ul className="max-h-96 overflow-auto">
                {items.map((n) => {
                    const { title, detail } = describeNotification(n);
                    const isUnread = !n.read_at;
                    return (
                        <li key={n.id}>
                            <button
                                type="button"
                                onClick={() => choose(n)}
                                className="flex w-full items-start gap-2 px-4 py-2.5 text-left hover:bg-[var(--color-paper)]"
                            >
                                <span
                                    aria-hidden="true"
                                    className={`mt-1.5 h-2 w-2 shrink-0 rounded-full ${isUnread ? 'bg-[var(--color-accent)]' : 'bg-transparent'}`}
                                />
                                <span className="flex min-w-0 flex-col">
                                    <span className={`truncate text-sm ${isUnread ? 'font-medium' : 'text-[var(--color-ink-muted)]'}`}>
                                        {title}
                                    </span>
                                    {detail && <span className="text-xs text-[var(--color-ink-muted)]">{detail}</span>}
                                </span>
                            </button>
                        </li>
                    );
                })}
            </ul>
        );
    }

    return (
        <div ref={rootRef} className="relative">
            <button
                type="button"
                onClick={() => setOpen((v) => !v)}
                aria-label={unread > 0 ? `Notifications, ${unread} unread` : 'Notifications'}
                aria-expanded={open}
                className="relative rounded-md p-1.5 text-[var(--color-ink-muted)] hover:bg-[var(--color-paper)] hover:text-[var(--color-ink)]"
            >
                <svg viewBox="0 0 24 24" className="h-5 w-5" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
                    <path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9" />
                    <path d="M10.3 21a1.94 1.94 0 0 0 3.4 0" />
                </svg>
                {unread > 0 && (
                    <span
                        className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-semibold leading-none text-white"
                        style={{ background: 'var(--color-accent)' }}
                    >
                        {unread > 99 ? '99+' : unread}
                    </span>
                )}
            </button>

            {open && (
                <div
                    role="region"
                    aria-label="Notifications"
                    className="absolute right-0 z-40 mt-2 w-80 overflow-hidden rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink)] shadow-lg"
                >
                    <div className="flex items-center justify-between border-b border-[var(--color-border)] px-4 py-2.5">
                        <span className="text-sm font-medium">Notifications</span>
                        <button
                            type="button"
                            onClick={() => markAll.mutate()}
                            disabled={unread === 0 || markAll.isPending}
                            className="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] disabled:opacity-40"
                        >
                            Mark all as read
                        </button>
                    </div>
                    {body}
                </div>
            )}
        </div>
    );
}
'@

# ---------------------------------------------------------------- 6. Wire into AppShell
Patch-Once -Path 'frontend\src\shared\components\AppShell.tsx' `
    -Old "import { GlobalSearch } from '@/features/search/components/GlobalSearch';" `
    -New ("import { GlobalSearch } from '@/features/search/components/GlobalSearch';`nimport { NotificationBell } from '@/features/notifications/components/NotificationBell';") `
    -Marker 'features/notifications/components/NotificationBell'

Patch-Once -Path 'frontend\src\shared\components\AppShell.tsx' `
    -Old '<span className="text-sm text-[var(--color-ink-muted)]">{user?.name}</span>' `
    -New ("<NotificationBell />`n                        <span className=`"text-sm text-[var(--color-ink-muted)]`">{user?.name}</span>") `
    -Marker '<NotificationBell />'

Write-Host ''
Write-Host 'Done. Next: cd frontend ; npx tsc --noEmit'
Write-Host 'NOTE: not shown, so not touched: router.tsx (no new routes needed), useCurrentUser internals (user.id/tenant.id are string|number), and whether Reverb is running locally (php artisan reverb:start) for the live push.'
