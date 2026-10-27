import { createRootRoute, createRoute, createRouter, Outlet } from '@tanstack/react-router';
import { RequireAuth } from '@/shared/components/RequireAuth';
import { AppShell } from '@/shared/components/AppShell';
import { LoginForm } from '@/features/auth/components/LoginForm';
import { DealsBoard } from '@/features/deals/components/Board';
import { ForecastDashboard } from '@/features/forecasting/components/Dashboard';
import { ContactsPage } from '@/features/contacts/components/ContactsPage';
import { AccountsPage } from '@/features/accounts/components/AccountsPage';
import { LeadsPage } from '@/features/leads/LeadsPage';
import { SettingsPage } from '@/features/settings/components/SettingsPage';
import { UsersPage } from '@/features/users/components/UsersPage';
import { validateUsersSearch } from '@/features/users/schemas/user';
import { AcceptInvitationPage } from '@/features/users/components/AcceptInvitationPage';
import { AuditLogPage } from '@/features/audit/components/AuditLogPage';
import { CampaignsPage } from '@/features/marketing/components/CampaignsPage';
import { validateCampaignsSearch } from '@/features/marketing/schemas/campaign';
import { SegmentsPage } from '@/features/marketing/components/SegmentsPage';
import { validateSegmentsSearch } from '@/features/marketing/schemas/segment';
import { validateAuditSearch } from '@/features/audit/schemas/audit';
import { createSegmentBuilderRoutes } from '@/features/marketing/segmentBuilderRoutes';
import { createTemplateRoutes } from '@/features/marketing/templateRoutes';
import { createAutomationRoutes } from '@/features/marketing/automationRoutes';
import { LeadFormsPage } from '@/features/marketing/components/LeadFormsPage';
import { CampaignDashboard } from '@/features/marketing/components/CampaignDashboard';
import { ProductsPage } from '@/features/products/components/ProductsPage';
import { validateProductsSearch } from '@/features/products/schemas/product';
const segmentBuilderRoutes = createSegmentBuilderRoutes(() => authenticatedLayoutRoute);
const templateRoutes = createTemplateRoutes(() => authenticatedLayoutRoute);
const automationRoutes = createAutomationRoutes(() => authenticatedLayoutRoute);

const rootRoute = createRootRoute({
    component: () => <Outlet />,
});

const dealsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/deals',
    component: DealsBoard,
});

const loginRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/login',
    component: () => (
        <div className="flex h-screen items-center justify-center bg-[var(--color-paper)]">
            <div className="flex flex-col gap-6 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] p-8">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Sign in</h1>
                <LoginForm />
            </div>
        </div>
    ),
});


const authenticatedLayoutRoute = createRoute({
    getParentRoute: () => rootRoute,
    id: 'authenticated',
    component: () => (
        <RequireAuth>
            <AppShell>
                <Outlet />
            </AppShell>
        </RequireAuth>
    ),
});

const dashboardRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/',
    component: () => (
        <div className="text-sm text-[var(--color-ink-muted)]">
            Phase 1 scaffold -- feature routes (Deals, Contacts, Forecast, ...) land in Phases 2-5.
        </div>
    ),
});

const contactsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/contacts',
    component: ContactsPage,
});

const leadsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/leads',
    component: LeadsPage,
});

const accountsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounts',
    component: AccountsPage,
});

const forecastRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/forecast',
    component: ForecastDashboard,
});

const settingsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/settings',
    component: SettingsPage,
});

const usersRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/users',
    validateSearch: validateUsersSearch,
    component: UsersPage,
});

// 9.1b: campaign list. Filters, sort and page live in the URL (8.7).
const marketingRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/marketing',
    validateSearch: validateCampaignsSearch,
    component: CampaignsPage,
});

// 9.2c: segment list. Filters, sort and page live in the URL (8.7). The builder routes arrive in 9.2d.
const segmentsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/marketing/segments',
    validateSearch: validateSegmentsSearch,
    component: SegmentsPage,
});

// 9.8b: lead capture forms (admin). The public page for a form is /f/{uuid} (9.8c), outside this layout.
const leadFormsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/marketing/forms',
    component: LeadFormsPage,
});

// 8c.3c: audit log viewer. Filters and page live in the URL (8.7).
const auditRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/audit',
    validateSearch: validateAuditSearch,
    component: AuditLogPage,
});

// 8c.2d: public (no RequireAuth): the emailed invitation link lands here.
const acceptInviteRoute = createRoute({
    getParentRoute: () => rootRoute,
    path: '/accept-invite/$token',
    component: AcceptInvitationPage,
});

// 10.0c: product catalog. Filters, sort and page live in the URL (8.7).
const productsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/products',
    validateSearch: validateProductsSearch,
    component: ProductsPage,
});

// 9.10: campaign dashboard.
const campaignDashboardRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/marketing/campaigns/$campaignId',
    component: CampaignDashboard,
});

const routeTree = rootRoute.addChildren([
    loginRoute,
    acceptInviteRoute,
    authenticatedLayoutRoute.addChildren([dashboardRoute, dealsRoute, contactsRoute, leadsRoute, accountsRoute, forecastRoute, settingsRoute, usersRoute, auditRoute, marketingRoute, segmentsRoute, ...segmentBuilderRoutes, ...templateRoutes, ...automationRoutes, leadFormsRoute, campaignDashboardRoute, productsRoute]),
]);
export const router = createRouter({ routeTree });

declare module '@tanstack/react-router' {
    interface Register {
        router: typeof router;
    }
}
