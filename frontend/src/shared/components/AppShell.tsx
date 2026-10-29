import { type CSSProperties, type ReactNode } from 'react';
import { Link } from '@tanstack/react-router';
import { useTenant } from '@/shared/hooks/useTenant';
import { usePermission } from '@/shared/hooks/usePermission';
import { useUiStore } from '@/shared/stores/ui';
import { useLogout } from '@/features/auth/api/useLogin';
import { useCurrentUser } from '@/features/auth/api/useCurrentUser';
import { GlobalSearch } from '@/features/search/components/GlobalSearch';
import { NotificationBell } from '@/features/notifications/components/NotificationBell';
import { brandingAccent } from '@/features/settings/schemas/settings';

type NavItem = {
    label: string;
    to: string;

    permission?: string;
};

const NAV_ITEMS: NavItem[] = [
    { label: 'Deals', to: '/deals', permission: 'deals.view' },
    { label: 'Contacts', to: '/contacts', permission: 'contacts.view' },
    { label: 'Leads', to: '/leads', permission: 'leads.view' },
    { label: 'Accounts', to: '/accounts', permission: 'accounts.view' },
    { label: 'Forecast', to: '/forecast', permission: 'forecast.view' },
    { label: 'Marketing', to: '/marketing', permission: 'marketing.view' },
    { label: 'Products', to: '/products', permission: 'products.view' },
    { label: 'Quotes', to: '/quotes', permission: 'quotes.view' },
    { label: 'Invoices', to: '/invoices', permission: 'invoices.view' },
    { label: 'Inventory', to: '/inventory', permission: 'inventory.view' },
    { label: 'Accounting', to: '/accounting', permission: 'accounting.view' },
    { label: 'HR', to: '/hr', permission: 'hr.view' },
    { label: 'Users', to: '/users', permission: 'users.manage' },
    { label: 'Audit log', to: '/audit', permission: 'audit.view' },
    { label: 'Settings', to: '/settings', permission: 'settings.manage' },
    { label: 'Admin', to: '/admin', permission: 'admin.access' },
];

export function AppShell({ children }: { children: ReactNode }) {
    const { tenant } = useTenant();
    const { data: user } = useCurrentUser();
    const { sidebarCollapsed, toggleSidebar } = useUiStore();
    const logout = useLogout();
    // 8c.1: the tenant's accent color (validated hex) drives the shell's --color-accent.
    const accent = brandingAccent(tenant?.branding);

    return (
        <div
            className="flex h-screen bg-[var(--color-paper)] text-[var(--color-ink)]"
            style={accent ? ({ '--color-accent': accent } as CSSProperties) : undefined}
        >
            <aside
                className={`flex flex-col border-r border-[var(--color-border)] bg-[var(--color-surface)] transition-all ${
                    sidebarCollapsed ? 'w-16' : 'w-56'
                }`}
            >
                <div className="flex h-14 items-center gap-2 border-b border-[var(--color-border)] px-4">
                    {tenant?.branding?.logo_url ? (
                        <img src={String(tenant.branding.logo_url)} alt="" className="h-6 w-6 rounded object-contain" />
                    ) : (
                        <div
                            className="flex h-6 w-6 shrink-0 items-center justify-center rounded text-xs font-semibold text-white"
                            style={{ background: 'var(--color-accent)' }}
                        >
                            {tenant?.name?.[0]?.toUpperCase() ?? '.'}
                        </div>
                    )}
                    {!sidebarCollapsed && <span className="truncate text-sm font-medium">{tenant?.name ?? 'Loading...'}</span>}
                </div>

                <nav className="flex flex-1 flex-col gap-0.5 px-2 py-3">
                    {NAV_ITEMS.map((item) => (
                        <NavLink key={item.to} item={item} collapsed={sidebarCollapsed} />
                    ))}
                </nav>

                <div className="border-t border-[var(--color-border)] p-2">
                    <button
                        onClick={toggleSidebar}
                        className="w-full rounded-md px-2 py-1.5 text-left text-xs text-[var(--color-ink-muted)] hover:bg-[var(--color-paper)]"
                    >
                        {sidebarCollapsed ? '>>' : '<< Collapse'}
                    </button>
                </div>
            </aside>

            <div className="flex flex-1 flex-col overflow-hidden">
                <header className="flex h-14 items-center justify-between border-b border-[var(--color-border)] bg-[var(--color-surface)] px-6">
                    <GlobalSearch />
                    <div className="flex items-center gap-3">
                        <NotificationBell />
                        <span className="text-sm text-[var(--color-ink-muted)]">{user?.name}</span>
                        <button
                            onClick={() => logout.mutate()}
                            disabled={logout.isPending}
                            className="text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] disabled:opacity-50"
                        >
                            {logout.isPending ? 'Signing out...' : 'Sign out'}
                        </button>
                    </div>
                </header>

                <main className="flex-1 overflow-auto p-6">{children}</main>
            </div>
        </div>
    );
}

function NavLink({ item, collapsed }: { item: NavItem; collapsed: boolean }) {

    const allowed = usePermission(item.permission ?? '');
    if (item.permission && !allowed) return null;

    return (
        <Link
            to={item.to}
            className="rounded-md px-2 py-1.5 text-sm text-[var(--color-ink-muted)] hover:bg-[var(--color-paper)] hover:text-[var(--color-ink)] [&.active]:bg-[var(--color-paper)] [&.active]:font-medium [&.active]:text-[var(--color-ink)]"
        >
            {collapsed ? item.label[0] : item.label}
        </Link>
    );
}
