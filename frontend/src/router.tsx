import { createRootRoute, createRoute, createRouter, lazyRouteComponent, Outlet } from '@tanstack/react-router';
import { RequireAuth } from '@/shared/components/RequireAuth';
import { AppShell } from '@/shared/components/AppShell';
import { LoginForm } from '@/features/auth/components/LoginForm';
import { RouteErrorFallback } from '@/shared/components/ErrorBoundary';
import { validateUsersSearch } from '@/features/users/schemas/user';
import { validateCampaignsSearch } from '@/features/marketing/schemas/campaign';
import { validateSegmentsSearch } from '@/features/marketing/schemas/segment';
import { validateAuditSearch } from '@/features/audit/schemas/audit';
import { createSegmentBuilderRoutes } from '@/features/marketing/segmentBuilderRoutes';
import { createTemplateRoutes } from '@/features/marketing/templateRoutes';
import { createAutomationRoutes } from '@/features/marketing/automationRoutes';
import { validateProductsSearch } from '@/features/products/schemas/product';
import { validateQuotesSearch } from '@/features/quotes/schemas/quote';
import { validateInvoicesSearch } from '@/features/invoices/schemas/invoice';
import { validatePurchaseOrdersSearch, validateSuppliersSearch } from '@/features/purchasing/schemas/purchasing';
import { validateInventorySearch } from '@/features/inventory/schemas/inventory';
import { validateJournalSearch } from '@/features/accounting/schemas/accounting';
import { validateAsOfSearch, validateLedgerSearch, validateRangeSearch } from '@/features/accounting/schemas/reports';
import { validateSyncSearch } from '@/features/accounting/schemas/sync';
import { validateEmployeesSearch, validateHrUnitsSearch } from '@/features/hr/schemas/hr';
import { validateLeaveSearch } from '@/features/hr/schemas/leave';

// 6.3: route-level code-splitting. Every page is its own chunk, loaded on first visit.
// Only the shell, the login form, the schema validators and the route plumbing stay in the main bundle.
const DealsBoard = lazyRouteComponent(() => import('@/features/deals/components/Board'), 'DealsBoard');
const ForecastDashboard = lazyRouteComponent(() => import('@/features/forecasting/components/Dashboard'), 'ForecastDashboard');
const ContactsPage = lazyRouteComponent(() => import('@/features/contacts/components/ContactsPage'), 'ContactsPage');
const AccountsPage = lazyRouteComponent(() => import('@/features/accounts/components/AccountsPage'), 'AccountsPage');
const LeadsPage = lazyRouteComponent(() => import('@/features/leads/LeadsPage'), 'LeadsPage');
const SettingsPage = lazyRouteComponent(() => import('@/features/settings/components/SettingsPage'), 'SettingsPage');
const UsersPage = lazyRouteComponent(() => import('@/features/users/components/UsersPage'), 'UsersPage');
const AcceptInvitationPage = lazyRouteComponent(() => import('@/features/users/components/AcceptInvitationPage'), 'AcceptInvitationPage');
const AuditLogPage = lazyRouteComponent(() => import('@/features/audit/components/AuditLogPage'), 'AuditLogPage');
const CampaignsPage = lazyRouteComponent(() => import('@/features/marketing/components/CampaignsPage'), 'CampaignsPage');
const SegmentsPage = lazyRouteComponent(() => import('@/features/marketing/components/SegmentsPage'), 'SegmentsPage');
const LeadFormsPage = lazyRouteComponent(() => import('@/features/marketing/components/LeadFormsPage'), 'LeadFormsPage');
const CampaignDashboard = lazyRouteComponent(() => import('@/features/marketing/components/CampaignDashboard'), 'CampaignDashboard');
const ProductsPage = lazyRouteComponent(() => import('@/features/products/components/ProductsPage'), 'ProductsPage');
const QuotesPage = lazyRouteComponent(() => import('@/features/quotes/components/QuotesPage'), 'QuotesPage');
const QuoteBuilder = lazyRouteComponent(() => import('@/features/quotes/components/QuoteBuilder'), 'QuoteBuilder');
const InvoicesPage = lazyRouteComponent(() => import('@/features/invoices/components/InvoicesPage'), 'InvoicesPage');
const InvoiceDetail = lazyRouteComponent(() => import('@/features/invoices/components/InvoiceDetail'), 'InvoiceDetail');
const InventoryPage = lazyRouteComponent(() => import('@/features/inventory/components/InventoryPage'), 'InventoryPage');
const ItemDetail = lazyRouteComponent(() => import('@/features/inventory/components/ItemDetail'), 'ItemDetail');
const SuppliersPage = lazyRouteComponent(() => import('@/features/purchasing/components/SuppliersPage'), 'SuppliersPage');
const PurchaseOrdersPage = lazyRouteComponent(() => import('@/features/purchasing/components/PurchaseOrdersPage'), 'PurchaseOrdersPage');
const PurchaseOrderBuilder = lazyRouteComponent(() => import('@/features/purchasing/components/PurchaseOrderBuilder'), 'PurchaseOrderBuilder');
const PurchaseOrderDetail = lazyRouteComponent(() => import('@/features/purchasing/components/PurchaseOrderDetail'), 'PurchaseOrderDetail');
const LedgerAccountsPage = lazyRouteComponent(() => import('@/features/accounting/components/AccountsPage'), 'LedgerAccountsPage');
const JournalPage = lazyRouteComponent(() => import('@/features/accounting/components/JournalPage'), 'JournalPage');
const JournalEntryBuilder = lazyRouteComponent(() => import('@/features/accounting/components/JournalEntryBuilder'), 'JournalEntryBuilder');
const JournalEntryDetail = lazyRouteComponent(() => import('@/features/accounting/components/JournalEntryDetail'), 'JournalEntryDetail');
const TrialBalancePage = lazyRouteComponent(() => import('@/features/accounting/components/TrialBalancePage'), 'TrialBalancePage');
const IncomeStatementPage = lazyRouteComponent(() => import('@/features/accounting/components/IncomeStatementPage'), 'IncomeStatementPage');
const BalanceSheetPage = lazyRouteComponent(() => import('@/features/accounting/components/BalanceSheetPage'), 'BalanceSheetPage');
const AccountLedgerPage = lazyRouteComponent(() => import('@/features/accounting/components/AccountLedgerPage'), 'AccountLedgerPage');
const SyncPage = lazyRouteComponent(() => import('@/features/accounting/components/SyncPage'), 'SyncPage');
const EmployeesPage = lazyRouteComponent(() => import('@/features/hr/components/EmployeesPage'), 'EmployeesPage');
const HrUnitsPage = lazyRouteComponent(() => import('@/features/hr/components/HrUnitsPage'), 'HrUnitsPage');
const LeavePage = lazyRouteComponent(() => import('@/features/hr/components/LeavePage'), 'LeavePage');

function PendingPage() {
    return <div className="p-4 text-sm text-[var(--color-ink-muted)]">Loading...</div>;
}

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

// 10.1c: quotes. The list keeps filters/sort/page in the URL (8.7); "new" is a literal path, so it wins over $quoteId.
const quotesRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/quotes',
    validateSearch: validateQuotesSearch,
    component: QuotesPage,
});

const quoteNewRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/quotes/new',
    component: QuoteBuilder,
});

const quoteEditRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/quotes/$quoteId',
    component: QuoteBuilder,
});

// 10.4c: invoices. The list keeps filters/sort/page in the URL (8.7).
const invoicesRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/invoices',
    validateSearch: validateInvoicesSearch,
    component: InvoicesPage,
});

const invoiceDetailRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/invoices/$invoiceId',
    component: InvoiceDetail,
});

// 9.10: campaign dashboard.
const campaignDashboardRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/marketing/campaigns/$campaignId',
    component: CampaignDashboard,
});

// 11.0c: inventory. The list keeps filters/sort/page in the URL (8.7).
const inventoryRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/inventory',
    validateSearch: validateInventorySearch,
    component: InventoryPage,
});

const inventoryDetailRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/inventory/$itemId',
    component: ItemDetail,
});

// 11.2c: suppliers and purchase orders. Lists keep filters/sort/page in the URL (8.7); "new" is a literal path, so it wins over $poId.
const suppliersRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/suppliers',
    validateSearch: validateSuppliersSearch,
    component: SuppliersPage,
});

const purchaseOrdersRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/purchase-orders',
    validateSearch: validatePurchaseOrdersSearch,
    component: PurchaseOrdersPage,
});

const purchaseOrderNewRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/purchase-orders/new',
    component: PurchaseOrderBuilder,
});

const purchaseOrderDetailRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/purchase-orders/$poId',
    component: PurchaseOrderDetail,
});

const purchaseOrderEditRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/purchase-orders/$poId/edit',
    component: PurchaseOrderBuilder,
});

// 12.1a: accounting. The journal is the landing page and keeps filters/page in the URL (8.7); "new" is a literal path, so it wins over $entryId.
const accountingRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting',
    validateSearch: validateJournalSearch,
    component: JournalPage,
});

const ledgerAccountsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/accounts',
    component: LedgerAccountsPage,
});

const journalNewRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/journal/new',
    component: JournalEntryBuilder,
});

const journalDetailRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/journal/$entryId',
    component: JournalEntryDetail,
});

// 12.1b: accounting reports. Dates/account/page live in the URL (8.7).
const trialBalanceRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/reports/trial-balance',
    validateSearch: validateAsOfSearch,
    component: TrialBalancePage,
});

const incomeStatementRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/reports/income-statement',
    validateSearch: validateRangeSearch,
    component: IncomeStatementPage,
});

const balanceSheetRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/reports/balance-sheet',
    validateSearch: validateAsOfSearch,
    component: BalanceSheetPage,
});

const accountLedgerRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/reports/ledger',
    validateSearch: validateLedgerSearch,
    component: AccountLedgerPage,
});

// 12.2c: ledger sync status and run history. Page lives in the URL (8.7).
const accountingSyncRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/accounting/sync',
    validateSearch: validateSyncSearch,
    component: SyncPage,
});

// 13.1c: HR. Employees keep filters/page in the URL (8.7); teams and territories share one page with a kind tab.
const employeesRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/hr',
    validateSearch: validateEmployeesSearch,
    component: EmployeesPage,
});

const hrUnitsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/hr/units',
    validateSearch: validateHrUnitsSearch,
    component: HrUnitsPage,
});

// 13.2b: leave requests. Status, sort and page live in the URL (8.7).
const leaveRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/hr/leave',
    validateSearch: validateLeaveSearch,
    component: LeavePage,
});

const routeTree = rootRoute.addChildren([
    loginRoute,
    acceptInviteRoute,
    authenticatedLayoutRoute.addChildren([
        dashboardRoute,
        dealsRoute,
        contactsRoute,
        leadsRoute,
        accountsRoute,
        forecastRoute,
        settingsRoute,
        usersRoute,
        auditRoute,
        marketingRoute,
        segmentsRoute,
        ...segmentBuilderRoutes,
        ...templateRoutes,
        ...automationRoutes,
        leadFormsRoute,
        campaignDashboardRoute,
        productsRoute,
        quotesRoute,
        quoteNewRoute,
        quoteEditRoute,
        invoicesRoute,
        invoiceDetailRoute,
        inventoryRoute,
        inventoryDetailRoute,
        suppliersRoute,
        purchaseOrdersRoute,
        purchaseOrderNewRoute,
        purchaseOrderDetailRoute,
        purchaseOrderEditRoute,
        accountingRoute,
        ledgerAccountsRoute,
        journalNewRoute,
        journalDetailRoute,
        trialBalanceRoute,
        incomeStatementRoute,
        balanceSheetRoute,
        accountLedgerRoute,
        accountingSyncRoute,
        employeesRoute,
        hrUnitsRoute,
        leaveRoute,
    ]),
]);

export const router = createRouter({
    routeTree,
    defaultPendingComponent: PendingPage,
    defaultPendingMs: 150,
    defaultErrorComponent: RouteErrorFallback,
});

declare module '@tanstack/react-router' {
    interface Register {
        router: typeof router;
    }
}
