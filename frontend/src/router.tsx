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
import { QuotesPage } from '@/features/quotes/components/QuotesPage';
import { QuoteBuilder } from '@/features/quotes/components/QuoteBuilder';
import { validateQuotesSearch } from '@/features/quotes/schemas/quote';
import { InvoicesPage } from '@/features/invoices/components/InvoicesPage';
import { InvoiceDetail } from '@/features/invoices/components/InvoiceDetail';
import { validateInvoicesSearch } from '@/features/invoices/schemas/invoice';
import { InventoryPage } from '@/features/inventory/components/InventoryPage';
import { SuppliersPage } from '@/features/purchasing/components/SuppliersPage';
import { PurchaseOrdersPage } from '@/features/purchasing/components/PurchaseOrdersPage';
import { PurchaseOrderBuilder } from '@/features/purchasing/components/PurchaseOrderBuilder';
import { PurchaseOrderDetail } from '@/features/purchasing/components/PurchaseOrderDetail';
import { validatePurchaseOrdersSearch, validateSuppliersSearch } from '@/features/purchasing/schemas/purchasing';
import { ItemDetail } from '@/features/inventory/components/ItemDetail';
import { validateInventorySearch } from '@/features/inventory/schemas/inventory';
import { LedgerAccountsPage } from '@/features/accounting/components/AccountsPage';
import { JournalPage } from '@/features/accounting/components/JournalPage';
import { JournalEntryBuilder } from '@/features/accounting/components/JournalEntryBuilder';
import { JournalEntryDetail } from '@/features/accounting/components/JournalEntryDetail';
import { validateJournalSearch } from '@/features/accounting/schemas/accounting';
import { TrialBalancePage } from '@/features/accounting/components/TrialBalancePage';
import { IncomeStatementPage } from '@/features/accounting/components/IncomeStatementPage';
import { BalanceSheetPage } from '@/features/accounting/components/BalanceSheetPage';
import { AccountLedgerPage } from '@/features/accounting/components/AccountLedgerPage';
import { validateAsOfSearch, validateLedgerSearch, validateRangeSearch } from '@/features/accounting/schemas/reports';
import { SyncPage } from '@/features/accounting/components/SyncPage';
import { validateSyncSearch } from '@/features/accounting/schemas/sync';
import { EmployeesPage } from '@/features/hr/components/EmployeesPage';
import { HrUnitsPage } from '@/features/hr/components/HrUnitsPage';
import { validateEmployeesSearch, validateHrUnitsSearch } from '@/features/hr/schemas/hr';
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

const routeTree = rootRoute.addChildren([
    loginRoute,
    acceptInviteRoute,
    authenticatedLayoutRoute.addChildren([dashboardRoute, dealsRoute, contactsRoute, leadsRoute, accountsRoute, forecastRoute, settingsRoute, usersRoute, auditRoute, marketingRoute, segmentsRoute, ...segmentBuilderRoutes, ...templateRoutes, ...automationRoutes, leadFormsRoute, campaignDashboardRoute, productsRoute, quotesRoute, quoteNewRoute, quoteEditRoute, invoicesRoute, invoiceDetailRoute, inventoryRoute, inventoryDetailRoute, suppliersRoute, purchaseOrdersRoute, purchaseOrderNewRoute, purchaseOrderDetailRoute, purchaseOrderEditRoute, accountingRoute, ledgerAccountsRoute, journalNewRoute, journalDetailRoute, trialBalanceRoute, incomeStatementRoute, balanceSheetRoute, accountLedgerRoute, accountingSyncRoute, employeesRoute, hrUnitsRoute]),
]);
export const router = createRouter({ routeTree });

declare module '@tanstack/react-router' {
    interface Register {
        router: typeof router;
    }
}
