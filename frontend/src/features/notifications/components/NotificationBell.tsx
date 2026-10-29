import { useEffect, useRef, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { useNotifications } from '../api/useNotifications';
import { useNotificationsLive } from '../api/useNotificationsLive';
import { useMarkAllNotificationsRead, useMarkNotificationRead } from '../api/useNotificationMutations';
import { LOW_STOCK_KIND, OVERDUE_KIND, type AppNotification } from '../schemas/notification';

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
    if (n.kind === LOW_STOCK_KIND) {
        return {
            title: `Low stock: ${n.data.sku ?? 'Item'}`,
            detail: `${n.data.name ?? ''} - ${n.data.on_hand ?? '?'} left (reorder at ${n.data.reorder_level ?? '?'})`,
        };
    }
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
        if (n.kind === LOW_STOCK_KIND && typeof n.data.item_id === 'number') {
            void navigate({ to: '/inventory/$itemId', params: { itemId: String(n.data.item_id) } });
            return;
        }
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
