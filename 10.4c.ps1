$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw "artisan not found in $repo" }
Set-Location $repo

$utf8 = New-Object System.Text.UTF8Encoding($false)
$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $script:report.Add($m) }

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = [System.IO.Path]::GetDirectoryName($Path)
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n").TrimEnd("`n") + "`n"
    [System.IO.File]::WriteAllText($Path, $c, $utf8)
}

function Count-Sub([string]$Text, [string]$Sub) {
    $n = 0; $i = 0
    while (($i = $Text.IndexOf($Sub, $i, [System.StringComparison]::Ordinal)) -ge 0) { $n++; $i += $Sub.Length }
    return $n
}

function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $raw = [System.IO.File]::ReadAllText($Path, $utf8)
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    if ($Skip -and $t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $Anchor) -ne 1) { throw "Anchor not exactly once in ${Path}: $Anchor" }
    $t = $t.Replace($Anchor, $Replacement)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, $utf8)
    return 'patched'
}

# ---------- prerequisites ----------
$routerPath = 'frontend\src\router.tsx'
$shellPath = 'frontend\src\shared\components\AppShell.tsx'
$barPath = 'frontend\src\features\quotes\components\QuoteWorkflowBar.tsx'
foreach ($p in @($routerPath, $shellPath, $barPath, 'frontend\src\features\quotes\schemas\quote.ts', 'frontend\src\features\quotes\api\useQuotes.ts')) {
    if (-not (Test-Path (Join-Path $repo $p))) { throw "Missing prerequisite: $p" }
}

# ---------- patch texts ----------
$routerRoutes = @'
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
'@

$barFn = @'
async function onCreateInvoice() {
        setError(null);
        try {
            const invoice = await createInvoice.mutateAsync({ quoteId: quote.id });
            await navigate({ to: '/invoices/$invoiceId', params: { invoiceId: String(invoice.id) } });
        } catch (err) {
            setError(apiErrorMessage(err, 'Could not create the invoice. Please try again.'));
        }
    }

    async function run(to: QuoteStatus, note?: string): Promise<boolean> {
'@

$barButton = @'
{canInvoice && quote.status === 'accepted' && (
                <div className="mt-2">
                    <button
                        type="button"
                        disabled={createInvoice.isPending}
                        onClick={() => void onCreateInvoice()}
                        className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {createInvoice.isPending ? 'Creating...' : 'Create invoice'}
                    </button>
                </div>
            )}

            {error && !rejectOpen && (
'@

$patches = @(
    @{ Path = $routerPath; Anchor = "import { validateQuotesSearch } from '@/features/quotes/schemas/quote';";
       Replacement = "import { validateQuotesSearch } from '@/features/quotes/schemas/quote';`nimport { InvoicesPage } from '@/features/invoices/components/InvoicesPage';`nimport { InvoiceDetail } from '@/features/invoices/components/InvoiceDetail';`nimport { validateInvoicesSearch } from '@/features/invoices/schemas/invoice';";
       Skip = 'InvoicesPage' },
    @{ Path = $routerPath; Anchor = '// 9.10: campaign dashboard.'; Replacement = $routerRoutes.TrimEnd(); Skip = 'invoiceDetailRoute = createRoute' },
    @{ Path = $routerPath; Anchor = 'quotesRoute, quoteNewRoute, quoteEditRoute]),';
       Replacement = 'quotesRoute, quoteNewRoute, quoteEditRoute, invoicesRoute, invoiceDetailRoute]),'; Skip = 'invoicesRoute, invoiceDetailRoute]' },
    @{ Path = $shellPath; Anchor = "{ label: 'Quotes', to: '/quotes', permission: 'quotes.view' },";
       Replacement = "{ label: 'Quotes', to: '/quotes', permission: 'quotes.view' },`n    { label: 'Invoices', to: '/invoices', permission: 'invoices.view' },"; Skip = "to: '/invoices'" },
    @{ Path = $barPath; Anchor = "import { usePermission } from '@/shared/hooks/usePermission';";
       Replacement = "import { useNavigate } from '@tanstack/react-router';`nimport { usePermission } from '@/shared/hooks/usePermission';`nimport { apiErrorMessage } from '@/shared/lib/apiErrorMessage';`nimport { useCreateInvoice } from '@/features/invoices/api/useInvoiceMutations';";
       Skip = 'useCreateInvoice' },
    @{ Path = $barPath; Anchor = 'const transition = useTransitionQuote();';
       Replacement = "const transition = useTransitionQuote();`n    const canInvoice = usePermission('invoices.manage');`n    const createInvoice = useCreateInvoice();`n    const navigate = useNavigate();";
       Skip = 'const canInvoice' },
    @{ Path = $barPath; Anchor = 'async function run(to: QuoteStatus, note?: string): Promise<boolean> {'; Replacement = $barFn.TrimEnd(); Skip = 'onCreateInvoice()' },
    @{ Path = $barPath; Anchor = 'if (targets.length === 0 && info.length === 0 && !quote.decision_note && !error) return null;';
       Replacement = "if (targets.length === 0 && info.length === 0 && !quote.decision_note && !error && !(canInvoice && quote.status === 'accepted')) return null;";
       Skip = "!(canInvoice && quote.status === 'accepted')" },
    @{ Path = $barPath; Anchor = '{error && !rejectOpen && ('; Replacement = $barButton.TrimEnd(); Skip = 'Create invoice' }
)

# ---------- preflight ----------
foreach ($p in $patches) {
    $t = [System.IO.File]::ReadAllText((Join-Path $repo $p.Path), $utf8).Replace("`r`n", "`n")
    if ($p.Skip -and $t.Contains($p.Skip)) { continue }
    if ((Count-Sub $t $p.Anchor) -ne 1) { throw "PREFLIGHT: anchor not exactly once in $($p.Path): $($p.Anchor)" }
}

# ---------- new files ----------
Write-NoBom 'frontend\src\features\invoices\schemas\invoice.ts' @'
import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';
import { TAX_CATEGORIES } from '@/features/products/schemas/product';

export const INVOICE_STATUSES = ['draft', 'issued', 'paid', 'void'] as const;
export type InvoiceStatus = (typeof INVOICE_STATUSES)[number];

export const INVOICE_STATUS_LABELS: Record<InvoiceStatus, string> = {
    draft: 'Draft',
    issued: 'Issued',
    paid: 'Paid',
    void: 'Void',
};

// Mirrors TransitionInvoiceAction::TRANSITIONS. The server is the authority; this only decides which buttons show.
export const INVOICE_TRANSITIONS: Record<InvoiceStatus, InvoiceStatus[]> = {
    draft: ['issued', 'void'],
    issued: ['paid', 'void'],
    paid: [],
    void: [],
};

export const INVOICE_ACTION_LABELS: Record<InvoiceStatus, string> = {
    draft: 'Back to draft',
    issued: 'Issue invoice',
    paid: 'Mark paid',
    void: 'Void',
};

// Mirrors InvoiceResource lines (10.4b). Money and quantities are decimal strings, never floats.
export const invoiceLineSchema = z.object({
    id: z.number(),
    position: z.number(),
    product_id: z.number().nullable(),
    variant_id: z.number().nullable(),
    sku: z.string().nullable(),
    name: z.string(),
    description: z.string().nullable(),
    quantity: z.string(),
    unit_price: z.string(),
    discount_percent: z.string(),
    tax_category: z.enum(TAX_CATEGORIES),
    tax_rate: z.string(),
    line_total: z.string(),
    tax_amount: z.string(),
});

// Mirrors InvoiceResource. lines_count is on list rows, lines on show/store responses.
export const invoiceSchema = z.object({
    id: z.number(),
    invoice_seq: z.number(),
    invoice_number: z.string(),
    status: z.enum(INVOICE_STATUSES),
    lock_version: z.number(),
    quote_id: z.number().nullable(),
    title: z.string(),
    account_id: z.number().nullable(),
    contact_id: z.number().nullable(),
    currency: z.string(),
    payment_terms_days: z.number(),
    issue_date: z.string().nullable(),
    due_date: z.string().nullable(),
    notes: z.string().nullable(),
    terms: z.string().nullable(),
    subtotal: z.string(),
    tax_total: z.string(),
    total: z.string(),
    issued_at: z.string().nullable(),
    paid_at: z.string().nullable(),
    voided_at: z.string().nullable(),
    void_reason: z.string().nullable(),
    created_by: z.number().nullable(),
    lines_count: z.number().optional(),
    lines: z.array(invoiceLineSchema).optional(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Invoice = z.infer<typeof invoiceSchema>;
export type InvoiceLine = z.infer<typeof invoiceLineSchema>;

export const invoicesPageSchema = z.object({
    data: z.array(invoiceSchema),
    meta: paginationMetaSchema,
});

export type InvoicesPage = z.infer<typeof invoicesPageSchema>;

export const INVOICE_SORT_COLUMNS = ['invoice_number', 'title', 'status', 'total', 'issue_date', 'due_date', 'created_at'] as const;
export type InvoiceSortColumn = (typeof INVOICE_SORT_COLUMNS)[number];

// URL-persisted filter/sort/page state for /invoices (8.7). Every field optional; defaults are dropped.
export type InvoicesSearch = {
    q?: string;
    status?: InvoiceStatus;
    sort?: InvoiceSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateInvoicesSearch(search: Record<string, unknown>): InvoicesSearch {
    const out: InvoicesSearch = {};

    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (INVOICE_STATUSES.includes(search.status as InvoiceStatus)) out.status = search.status as InvoiceStatus;
    if (INVOICE_SORT_COLUMNS.includes(search.sort as InvoiceSortColumn)) out.sort = search.sort as InvoiceSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}
'@

Write-NoBom 'frontend\src\features\invoices\api\useInvoices.ts' @'
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { invoiceSchema, invoicesPageSchema, type Invoice, type InvoiceSortColumn, type InvoiceStatus, type InvoicesPage } from '../schemas/invoice';

export type InvoicesListParams = {
    page?: number;
    perPage?: number;
    sort?: InvoiceSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    status?: InvoiceStatus;
    enabled?: boolean;
};

// Tenant-namespaced keys (1.2). The root prefix covers lists and details: mutations invalidate it as a whole.
export function invoicesRootKey(tenantId: unknown) {
    return ['invoices', tenantId] as const;
}

export function invoicesListKey(tenantId: unknown) {
    return [...invoicesRootKey(tenantId), 'list'] as const;
}

export function invoiceDetailKey(tenantId: unknown, invoiceId: number) {
    return [...invoicesRootKey(tenantId), 'detail', invoiceId] as const;
}

export function useInvoices(params: InvoicesListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'invoice_number', direction = 'desc', q = '', status, enabled = true } = params;

    return useQuery({
        queryKey: [...invoicesListKey(tenant?.id), { page, perPage, sort, direction, q, status }] as const,
        queryFn: async (): Promise<InvoicesPage> => {
            const { data } = await apiClient.get('/invoices', {
                params: { page, per_page: perPage, sort, direction, q: q || undefined, status: status || undefined },
            });
            return invoicesPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}

// The list carries lines_count only; the detail page needs the lines, so it loads the detail fresh.
export function useInvoice(invoiceId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: invoiceDetailKey(tenant?.id, invoiceId ?? 0),
        queryFn: async (): Promise<Invoice> => {
            const { data } = await apiClient.get(`/invoices/${invoiceId}`);
            return invoiceSchema.parse(data);
        },
        enabled: !!tenant?.id && invoiceId !== null,
        staleTime: 0,
    });
}
'@

Write-NoBom 'frontend\src\features\invoices\api\useInvoiceMutations.ts' @'
import { useMutation, useQueryClient, type QueryKey } from '@tanstack/react-query';
import { apiClient, isApiError } from '@/shared/lib/apiClient';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useTenant } from '@/shared/hooks/useTenant';
import { invoiceSchema, type Invoice, type InvoiceStatus, type InvoicesPage } from '../schemas/invoice';
import { invoiceDetailKey, invoicesListKey, invoicesRootKey } from './useInvoices';

export type CreateInvoiceVars = { quoteId: number; paymentTermsDays?: number };

// Not optimistic: the server allocates the number and snapshots the quote. Tenant comes from the session (1.3).
export function useCreateInvoice() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ quoteId, paymentTermsDays }: CreateInvoiceVars): Promise<Invoice> => {
            const { data } = await apiClient.post('/invoices', {
                quote_id: quoteId,
                ...(paymentTermsDays !== undefined ? { payment_terms_days: paymentTermsDays } : {}),
            });
            return invoiceSchema.parse(data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: invoicesRootKey(tenant?.id) }),
    });
}

export type TransitionInvoiceVars = {
    invoice: Invoice;
    toStatus: InvoiceStatus;
    // Required by the server when voiding; optional otherwise.
    note?: string;
};

type Context = {
    previousLists: Array<[QueryKey, InvoicesPage | undefined]>;
    previousDetail: Invoice | undefined;
};

function patchRow(page: InvoicesPage | undefined, id: number, fn: (i: Invoice) => Invoice): InvoicesPage | undefined {
    if (!page) return page;
    return { ...page, data: page.data.map((i) => (i.id === id ? fn(i) : i)) };
}

export const STALE_INVOICE_MESSAGE = 'This invoice was updated by someone else - the latest version is shown now.';

export function isStaleInvoiceError(err: unknown): boolean {
    return isApiError(err) && err.response?.status === 409;
}

// 409 = lock_version mismatch (3.0 pattern). 422 (invalid_transition, note_required) carries the server's own
// sentence, which apiErrorMessage prefers.
export function transitionInvoiceErrorMessage(err: unknown): string {
    if (isStaleInvoiceError(err)) return STALE_INVOICE_MESSAGE;
    return apiErrorMessage(err, 'Could not update the invoice. Please try again.');
}

// 3.0 pattern: optimistic patch of every cached list page and the detail, roll back on any error, reconcile with
// the server's copy (the response, or `current` on a 409), then invalidate.
export function useTransitionInvoice() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();
    const listKey = invoicesListKey(tenant?.id);

    function applyServerCopy(server: Invoice) {
        // List rows never carry lines; keep their lines_count.
        // eslint-disable-next-line @typescript-eslint/no-unused-vars
        const { lines: _lines, ...rest } = server;
        queryClient.setQueriesData<InvoicesPage>({ queryKey: listKey }, (old) =>
            patchRow(old, server.id, (i) => ({ ...i, ...rest, lines_count: i.lines_count })),
        );
        queryClient.setQueryData<Invoice>(invoiceDetailKey(tenant?.id, server.id), (old) => (old ? { ...old, ...server } : old));
    }

    return useMutation<Invoice, unknown, TransitionInvoiceVars, Context>({
        mutationFn: async ({ invoice, toStatus, note }) => {
            const { data } = await apiClient.post(`/invoices/${invoice.id}/transition`, {
                to_status: toStatus,
                lock_version: invoice.lock_version,
                ...(note ? { note } : {}),
            });
            return invoiceSchema.parse(data);
        },
        onMutate: async (vars) => {
            const key = invoiceDetailKey(tenant?.id, vars.invoice.id);

            await queryClient.cancelQueries({ queryKey: listKey });
            await queryClient.cancelQueries({ queryKey: key });

            const previousLists = queryClient.getQueriesData<InvoicesPage>({ queryKey: listKey });
            const previousDetail = queryClient.getQueryData<Invoice>(key);

            queryClient.setQueriesData<InvoicesPage>({ queryKey: listKey }, (old) =>
                patchRow(old, vars.invoice.id, (i) => ({ ...i, status: vars.toStatus })),
            );
            if (previousDetail) {
                queryClient.setQueryData<Invoice>(key, { ...previousDetail, status: vars.toStatus });
            }

            return { previousLists, previousDetail };
        },
        onError: (err, vars, context) => {
            context?.previousLists.forEach(([key, data]) => queryClient.setQueryData(key, data));
            if (context?.previousDetail) {
                queryClient.setQueryData(invoiceDetailKey(tenant?.id, vars.invoice.id), context.previousDetail);
            }

            // 409: someone else moved it first. The response carries their version: show it right away.
            if (isStaleInvoiceError(err) && isApiError(err)) {
                const current = invoiceSchema.safeParse((err.response?.data as { current?: unknown } | undefined)?.current);
                if (current.success) applyServerCopy(current.data);
            }
        },
        onSuccess: (server) => applyServerCopy(server),
        onSettled: () => queryClient.invalidateQueries({ queryKey: invoicesRootKey(tenant?.id) }),
    });
}
'@

Write-NoBom 'frontend\src\features\invoices\components\InvoicesPage.tsx' @'
import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { Link, useNavigate, useSearch } from '@tanstack/react-router';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { useInvoices } from '../api/useInvoices';
import {
    INVOICE_STATUSES,
    INVOICE_STATUS_LABELS,
    validateInvoicesSearch,
    type Invoice,
    type InvoiceSortColumn,
    type InvoiceStatus,
    type InvoicesSearch,
} from '../schemas/invoice';

const columnHelper = createColumnHelper<Invoice>();
const EMPTY: Invoice[] = [];

export function InvoicesPage() {
    const allowed = usePermission('invoices.view');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateInvoicesSearch(raw);

    const page = search.page ?? 1;
    const sort: InvoiceSortColumn = search.sort ?? 'invoice_number';
    const direction = search.direction ?? 'desc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<InvoicesSearch>) {
        void navigate({ to: '/invoices', search: validateInvoicesSearch({ ...search, ...patch }), replace: true });
    }

    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useInvoices({ page, sort, direction, q: search.q, status: search.status, enabled: allowed });

    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const columns = useMemo(
        () => [
            columnHelper.accessor('invoice_number', {
                header: 'Number',
                cell: (info) => (
                    <Link to="/invoices/$invoiceId" params={{ invoiceId: String(info.row.original.id) }} className="font-mono text-xs underline">
                        {info.getValue()}
                    </Link>
                ),
            }),
            columnHelper.accessor('title', {
                header: 'Title',
                cell: (info) => <span className="font-medium">{info.getValue()}</span>,
            }),
            columnHelper.accessor('status', {
                header: 'Status',
                cell: (info) => (
                    <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                        {INVOICE_STATUS_LABELS[info.getValue()]}
                    </span>
                ),
            }),
            columnHelper.accessor('total', {
                header: 'Total',
                cell: (info) => formatMoney(info.getValue(), info.row.original.currency),
            }),
            columnHelper.accessor('due_date', {
                header: 'Due',
                cell: (info) => info.getValue() ?? '-',
            }),
            columnHelper.accessor('lines_count', {
                header: 'Lines',
                cell: (info) => info.getValue() ?? 0,
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => (
                    <Link to="/invoices/$invoiceId" params={{ invoiceId: String(row.original.id) }} className="rounded-md border px-2 py-1 text-xs">
                        Open
                    </Link>
                ),
                enableSorting: false,
            }),
        ],
        [],
    );

    const sorting: SortingState = [{ id: sort, desc: direction === 'desc' }];

    const table = useReactTable({
        data: data?.data ?? EMPTY,
        columns,
        state: { sorting },
        onSortingChange: (updater) => {
            const next = typeof updater === 'function' ? updater(sorting) : updater;
            const first = next[0];
            if (!first) return;
            setSearch({ sort: first.id as InvoiceSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to invoices.</p>;
    }

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.status);

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Invoices</h1>
                {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    placeholder="Search number or title..."
                    value={qInput}
                    onChange={(e) => setQInput(e.target.value)}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                <select
                    aria-label="Filter by status"
                    value={search.status ?? ''}
                    onChange={(e) => setSearch({ status: (e.target.value || undefined) as InvoiceStatus | undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All statuses</option>
                    {INVOICE_STATUSES.map((s) => (
                        <option key={s} value={s}>
                            {INVOICE_STATUS_LABELS[s]}
                        </option>
                    ))}
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/invoices', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load invoices.")}
                </p>
            )}

            {isPending ? (
                <p className="text-sm text-gray-500">Loading...</p>
            ) : (
                <table className="w-full border-collapse text-sm">
                    <thead>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <tr key={headerGroup.id}>
                                {headerGroup.headers.map((header) => {
                                    const canSort = header.column.getCanSort();
                                    const sortDir = header.column.getIsSorted();
                                    return (
                                        <th
                                            key={header.id}
                                            onClick={canSort ? header.column.getToggleSortingHandler() : undefined}
                                            className={`border-b px-3 py-2 text-left font-medium ${canSort ? 'cursor-pointer select-none' : ''}`}
                                        >
                                            {header.isPlaceholder ? null : flexRender(header.column.columnDef.header, header.getContext())}
                                            {sortDir === 'asc' ? ' \u25b2' : sortDir === 'desc' ? ' \u25bc' : ''}
                                        </th>
                                    );
                                })}
                            </tr>
                        ))}
                    </thead>
                    <tbody>
                        {table.getRowModel().rows.map((row) => (
                            <tr key={row.original.id}>
                                {row.getVisibleCells().map((cell) => (
                                    <td key={cell.id} className="border-b px-3 py-2 align-top">
                                        {flexRender(cell.column.columnDef.cell, cell.getContext())}
                                    </td>
                                ))}
                            </tr>
                        ))}
                        {table.getRowModel().rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length} className="px-3 py-6 text-center text-gray-500">
                                    {hasFilters ? 'No invoices match these filters.' : 'No invoices yet. Create one from an accepted quote.'}
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            )}

            {meta && (
                <div className="mt-3 flex items-center justify-between text-sm text-gray-600">
                    <span>
                        {meta.from ?? 0}-{meta.to ?? 0} of {meta.total}
                    </span>
                    <div className="flex gap-2">
                        <button type="button" disabled={page <= 1} onClick={() => setSearch({ page: page - 1 })} className="rounded-md border px-2 py-1 disabled:opacity-40">
                            Previous
                        </button>
                        <button
                            type="button"
                            disabled={page >= meta.last_page}
                            onClick={() => setSearch({ page: page + 1 })}
                            className="rounded-md border px-2 py-1 disabled:opacity-40"
                        >
                            Next
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}
'@

Write-NoBom 'frontend\src\features\invoices\components\InvoiceDetail.tsx' @'
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { Link, useParams } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { formatMoney } from '@/features/quotes/schemas/quote';
import { useInvoice } from '../api/useInvoices';
import { transitionInvoiceErrorMessage, useTransitionInvoice } from '../api/useInvoiceMutations';
import { INVOICE_ACTION_LABELS, INVOICE_STATUS_LABELS, INVOICE_TRANSITIONS, type Invoice, type InvoiceStatus } from '../schemas/invoice';

const voidSchema = z.object({
    note: z.string().trim().min(1, 'Enter a reason for voiding this invoice.').max(1000, 'Keep it under 1000 characters.'),
});
type VoidValues = z.infer<typeof voidSchema>;

function when(value: string | null | undefined): string {
    if (!value) return '';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
}

// 10.4c: status actions. Buttons mirror the server's transition map and the invoices.manage permission; the
// server re-checks everything and answers a stale lock_version with 409 + the current invoice.
function InvoiceWorkflowBar({ invoice }: { invoice: Invoice }) {
    const canManage = usePermission('invoices.manage');
    const transition = useTransitionInvoice();
    const [error, setError] = useState<string | null>(null);
    const [voidOpen, setVoidOpen] = useState(false);

    const {
        register,
        handleSubmit,
        reset,
        formState: { errors, isSubmitting },
    } = useForm<VoidValues>({ resolver: zodResolver(voidSchema), defaultValues: { note: '' } });

    const targets = canManage ? INVOICE_TRANSITIONS[invoice.status] : [];

    async function run(to: InvoiceStatus, note?: string): Promise<boolean> {
        setError(null);
        try {
            await transition.mutateAsync({ invoice, toStatus: to, note });
            return true;
        } catch (err) {
            setError(transitionInvoiceErrorMessage(err));
            return false;
        }
    }

    const onVoid = handleSubmit(async (values) => {
        const ok = await run('void', values.note);
        if (ok) {
            setVoidOpen(false);
            reset({ note: '' });
        }
    });

    function closeVoid() {
        setVoidOpen(false);
        setError(null);
        reset({ note: '' });
    }

    if (targets.length === 0 && !error) return null;

    return (
        <div className="mb-3 rounded-md border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2">
            {targets.length > 0 && (
                <div className="flex flex-wrap gap-2">
                    {targets.map((to) => (
                        <button
                            key={to}
                            type="button"
                            disabled={transition.isPending}
                            onClick={() => {
                                if (to === 'void') {
                                    setError(null);
                                    setVoidOpen(true);
                                } else {
                                    void run(to);
                                }
                            }}
                            className={
                                to === 'issued' || to === 'paid'
                                    ? 'rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50'
                                    : 'rounded-md border px-3 py-1.5 text-sm text-red-700 disabled:opacity-50'
                            }
                        >
                            {transition.isPending && transition.variables?.toStatus === to ? 'Working...' : INVOICE_ACTION_LABELS[to]}
                        </button>
                    ))}
                </div>
            )}

            {error && !voidOpen && (
                <p role="alert" className="mt-2 text-sm text-red-700">
                    {error}
                </p>
            )}

            <Modal open={voidOpen} onClose={closeVoid} title="Void invoice">
                <form onSubmit={onVoid} noValidate className="flex flex-col gap-3">
                    <div>
                        <label htmlFor="invoice-void-note" className="mb-1 block text-sm font-medium">
                            Reason
                        </label>
                        <textarea
                            id="invoice-void-note"
                            rows={4}
                            aria-invalid={errors.note ? true : undefined}
                            className="w-full rounded-md border px-3 py-2 text-sm"
                            {...register('note')}
                        />
                        {errors.note?.message && (
                            <p role="alert" className="mt-1 text-xs text-red-700">
                                {errors.note.message}
                            </p>
                        )}
                    </div>
                    {error && voidOpen && (
                        <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                            {error}
                        </p>
                    )}
                    <div className="flex justify-end gap-2">
                        <button type="button" onClick={closeVoid} disabled={isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                            Cancel
                        </button>
                        <button
                            type="submit"
                            disabled={isSubmitting}
                            className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                        >
                            {isSubmitting ? 'Voiding...' : 'Void invoice'}
                        </button>
                    </div>
                </form>
            </Modal>
        </div>
    );
}

function InvoiceView({ invoice }: { invoice: Invoice }) {
    const lines = invoice.lines ?? [];
    const money = (v: string) => formatMoney(v, invoice.currency);

    return (
        <div className="max-w-5xl">
            <div className="mb-4 flex items-center gap-3">
                <Link to="/invoices" search={{}} className="text-sm text-[var(--color-ink-muted)] underline">
                    Invoices
                </Link>
                <h1 className="text-lg font-medium text-[var(--color-ink)]">{invoice.invoice_number}</h1>
                <span className="inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-700">
                    {INVOICE_STATUS_LABELS[invoice.status]}
                </span>
            </div>

            <InvoiceWorkflowBar invoice={invoice} />

            <dl className="mb-4 grid grid-cols-2 gap-x-6 gap-y-2 text-sm md:grid-cols-4">
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Title</dt>
                    <dd className="font-medium">{invoice.title}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Issue date</dt>
                    <dd>{invoice.issue_date ?? '-'}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Due date</dt>
                    <dd>{invoice.due_date ?? `${invoice.payment_terms_days} days after issue`}</dd>
                </div>
                <div>
                    <dt className="text-[var(--color-ink-muted)]">Quote</dt>
                    <dd>
                        {invoice.quote_id !== null ? (
                            <Link to="/quotes/$quoteId" params={{ quoteId: String(invoice.quote_id) }} className="underline">
                                View quote
                            </Link>
                        ) : (
                            '-'
                        )}
                    </dd>
                </div>
            </dl>

            {(invoice.paid_at || invoice.voided_at) && (
                <p className="mb-3 text-sm text-[var(--color-ink-muted)]">
                    {invoice.paid_at && `Paid ${when(invoice.paid_at)}`}
                    {invoice.voided_at && `Voided ${when(invoice.voided_at)}`}
                    {invoice.void_reason && ` - ${invoice.void_reason}`}
                </p>
            )}

            <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                    <thead>
                        <tr>
                            <th className="border-b px-3 py-2 text-left font-medium">Item</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Qty</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Unit price</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Disc %</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Tax</th>
                            <th className="border-b px-3 py-2 text-right font-medium">Line total</th>
                        </tr>
                    </thead>
                    <tbody>
                        {lines.map((l) => (
                            <tr key={l.id}>
                                <td className="border-b px-3 py-2 align-top">
                                    <div className="font-medium">{l.name}</div>
                                    {l.sku && <div className="font-mono text-xs text-[var(--color-ink-muted)]">{l.sku}</div>}
                                    {l.description && <div className="text-xs text-[var(--color-ink-muted)]">{l.description}</div>}
                                </td>
                                <td className="border-b px-3 py-2 text-right align-top">{String(Number(l.quantity))}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.unit_price)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{String(Number(l.discount_percent))}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.tax_amount)}</td>
                                <td className="border-b px-3 py-2 text-right align-top">{money(l.line_total)}</td>
                            </tr>
                        ))}
                        {lines.length === 0 && (
                            <tr>
                                <td colSpan={6} className="px-3 py-6 text-center text-gray-500">
                                    No lines.
                                </td>
                            </tr>
                        )}
                    </tbody>
                </table>
            </div>

            <dl className="mt-4 ml-auto grid w-64 grid-cols-2 gap-y-1 text-sm">
                <dt className="text-[var(--color-ink-muted)]">Subtotal</dt>
                <dd className="text-right">{money(invoice.subtotal)}</dd>
                <dt className="text-[var(--color-ink-muted)]">Tax</dt>
                <dd className="text-right">{money(invoice.tax_total)}</dd>
                <dt className="font-medium">Total</dt>
                <dd className="text-right font-medium">{money(invoice.total)}</dd>
            </dl>

            {(invoice.notes || invoice.terms) && (
                <div className="mt-6 grid grid-cols-1 gap-4 text-sm md:grid-cols-2">
                    {invoice.notes && (
                        <div>
                            <h2 className="mb-1 font-medium">Notes</h2>
                            <p className="whitespace-pre-wrap text-[var(--color-ink-muted)]">{invoice.notes}</p>
                        </div>
                    )}
                    {invoice.terms && (
                        <div>
                            <h2 className="mb-1 font-medium">Terms</h2>
                            <p className="whitespace-pre-wrap text-[var(--color-ink-muted)]">{invoice.terms}</p>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

export function InvoiceDetail() {
    const viewAllowed = usePermission('invoices.view');
    const { invoiceId } = useParams({ strict: false }) as { invoiceId?: string };
    const id = invoiceId === undefined ? null : Number(invoiceId);
    const validId = id !== null && Number.isInteger(id) && id > 0 ? id : null;
    const detail = useInvoice(validId);

    if (!viewAllowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to invoices.</p>;
    }

    if (validId === null) return <p className="text-sm text-[var(--color-ink-muted)]">Invoice not found.</p>;

    if (detail.isPending) return <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>;

    if (detail.isError) {
        return (
            <div className="flex flex-col gap-3">
                <p role="alert" className="text-sm text-red-700">
                    {apiErrorMessage(detail.error, 'Could not load the invoice.')}
                </p>
                <Link to="/invoices" search={{}} className="text-sm underline">
                    Back to invoices
                </Link>
            </div>
        );
    }

    return <InvoiceView key={detail.data.id} invoice={detail.data} />;
}
'@

# ---------- apply patches ----------
foreach ($p in $patches) {
    $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip
    Say "$res : $($p.Path)"
}

# ---------- typecheck ----------
Say 'Type-checking the frontend...'
Set-Location (Join-Path $repo 'frontend')
$ErrorActionPreference = 'Continue'
$out = & npx.cmd tsc --noEmit 2>&1 | Out-String
$code = $LASTEXITCODE
$ErrorActionPreference = 'Stop'
Set-Location $repo
if ($code -eq 0) { Say 'tsc: OK' } else { Say "tsc: FAILED`n$out" }

Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): backend (unchanged), the AppShell placeholder nav items (Inventory, Accounting, Admin), no account/contact names on invoices yet (ids only).'
