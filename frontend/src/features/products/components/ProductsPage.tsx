import { useEffect, useMemo, useState } from 'react';
import { createColumnHelper, flexRender, getCoreRowModel, useReactTable, type SortingState } from '@tanstack/react-table';
import { useNavigate, useSearch } from '@tanstack/react-router';
import { Modal } from '@/shared/components/Modal';
import { usePermission } from '@/shared/hooks/usePermission';
import { useDebouncedValue } from '@/shared/hooks/useDebouncedValue';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useProducts } from '../api/useProducts';
import { useDeleteProduct } from '../api/useProductMutations';
import {
    TAX_CATEGORIES,
    validateProductsSearch,
    type Product,
    type ProductSortColumn,
    type ProductStatusFilter,
    type ProductsSearch,
    type TaxCategory,
} from '../schemas/product';
import { ProductFormDialog } from './ProductFormDialog';

const columnHelper = createColumnHelper<Product>();
const EMPTY: Product[] = [];

function formatPrice(value: string): string {
    const n = Number(value);
    return Number.isNaN(n) ? value : n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function capitalize(s: string): string {
    return s.charAt(0).toUpperCase() + s.slice(1);
}

export function ProductsPage() {
    const allowed = usePermission('products.view');
    const canManage = usePermission('products.manage');
    const navigate = useNavigate();
    const raw = useSearch({ strict: false }) as unknown as Record<string, unknown>;
    const search = validateProductsSearch(raw);

    const page = search.page ?? 1;
    const sort: ProductSortColumn = search.sort ?? 'name';
    const direction = search.direction ?? 'asc';

    // Single place that writes filters/sort/page back to the URL; defaults and empties are dropped.
    function setSearch(patch: Partial<ProductsSearch>) {
        void navigate({ to: '/products', search: validateProductsSearch({ ...search, ...patch }), replace: true });
    }

    // Search box: local state for typing, debounced into the URL (and from there into the query key).
    const [qInput, setQInput] = useState(search.q ?? '');
    const debouncedQ = useDebouncedValue(qInput, 300);
    useEffect(() => {
        const next = debouncedQ.trim();
        if (next !== (search.q ?? '')) setSearch({ q: next, page: undefined });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [debouncedQ]);

    const { data, isPending, isFetching, isError, error } = useProducts({
        page,
        sort,
        direction,
        q: search.q,
        status: search.status,
        taxCategory: search.tax_category,
        enabled: allowed,
    });

    // After deleting the last row of the last page, step back instead of showing an empty page.
    useEffect(() => {
        if (data && data.data.length === 0 && page > 1) {
            setSearch({ page: Math.max(1, Math.min(page - 1, data.meta.last_page)) });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [data, page]);

    const del = useDeleteProduct();
    const [pendingDelete, setPendingDelete] = useState<Product | null>(null);
    // null = closed, 'new' = create dialog, Product = edit dialog.
    const [formTarget, setFormTarget] = useState<Product | 'new' | null>(null);

    const columns = useMemo(
        () => [
            columnHelper.accessor('sku', {
                header: 'SKU',
                cell: (info) => <span className="font-mono text-xs">{info.getValue()}</span>,
            }),
            columnHelper.accessor('name', {
                header: 'Product',
                cell: (info) => (
                    <div>
                        <div className="font-medium">{info.getValue()}</div>
                        {info.row.original.description && (
                            <div className="max-w-md truncate text-xs text-[var(--color-ink-muted)]">{info.row.original.description}</div>
                        )}
                    </div>
                ),
            }),
            columnHelper.accessor('unit_price', {
                header: 'Price',
                cell: (info) => formatPrice(info.getValue()),
            }),
            columnHelper.accessor('tax_category', {
                header: 'Tax',
                cell: (info) => capitalize(info.getValue()),
                enableSorting: false,
            }),
            columnHelper.accessor('variants_count', {
                header: 'Variants',
                cell: (info) => info.getValue() ?? 0,
                enableSorting: false,
            }),
            columnHelper.accessor('is_active', {
                header: 'Status',
                cell: (info) => (
                    <span
                        className={`inline-block rounded-full px-2 py-0.5 text-xs font-medium ${
                            info.getValue() ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-700'
                        }`}
                    >
                        {info.getValue() ? 'Active' : 'Inactive'}
                    </span>
                ),
                enableSorting: false,
            }),
            columnHelper.display({
                id: 'actions',
                header: '',
                cell: ({ row }) => {
                    if (!canManage) return null;
                    const p = row.original;
                    return (
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setFormTarget(p)} className="rounded-md border px-2 py-1 text-xs">
                                Edit
                            </button>
                            <button
                                type="button"
                                onClick={() => {
                                    del.reset();
                                    setPendingDelete(p);
                                }}
                                className="rounded-md border px-2 py-1 text-xs text-red-700"
                            >
                                Delete
                            </button>
                        </div>
                    );
                },
                enableSorting: false,
            }),
        ],
        // del.reset is stable; the setters are state setters.
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [canManage],
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
            setSearch({ sort: first.id as ProductSortColumn, direction: first.desc ? 'desc' : 'asc', page: undefined });
        },
        enableSortingRemoval: false,
        manualPagination: true,
        manualSorting: true,
        getCoreRowModel: getCoreRowModel(),
    });

    if (!allowed) {
        return <p className="text-sm text-[var(--color-ink-muted)]">You don&apos;t have access to products.</p>;
    }

    const meta = data?.meta;
    const hasFilters = !!(search.q || search.status || search.tax_category);

    function confirmDelete() {
        if (!pendingDelete) return;
        del.mutate(pendingDelete.id, { onSuccess: () => setPendingDelete(null) });
    }

    function closeDelete() {
        del.reset();
        setPendingDelete(null);
    }

    return (
        <div>
            <div className="mb-4 flex items-center justify-between">
                <h1 className="text-lg font-medium text-[var(--color-ink)]">Products</h1>
                <div className="flex items-center gap-3">
                    {isFetching && !isPending && <span className="text-xs text-gray-400">Refreshing...</span>}
                    {canManage && (
                        <button
                            type="button"
                            onClick={() => setFormTarget('new')}
                            className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90"
                        >
                            New product
                        </button>
                    )}
                </div>
            </div>

            <div className="mb-3 flex flex-wrap items-center gap-2">
                <input
                    type="search"
                    placeholder="Search SKU or name..."
                    value={qInput}
                    onChange={(e) => setQInput(e.target.value)}
                    className="w-64 rounded-md border px-3 py-2 text-sm"
                />
                <select
                    aria-label="Filter by status"
                    value={search.status ?? ''}
                    onChange={(e) => setSearch({ status: (e.target.value || undefined) as ProductStatusFilter | undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
                <select
                    aria-label="Filter by tax category"
                    value={search.tax_category ?? ''}
                    onChange={(e) => setSearch({ tax_category: (e.target.value || undefined) as TaxCategory | undefined, page: undefined })}
                    className="rounded-md border px-2 py-2 text-sm"
                >
                    <option value="">All tax categories</option>
                    {TAX_CATEGORIES.map((c) => (
                        <option key={c} value={c}>
                            {capitalize(c)}
                        </option>
                    ))}
                </select>
                {hasFilters && (
                    <button
                        type="button"
                        onClick={() => {
                            setQInput('');
                            void navigate({ to: '/products', search: {}, replace: true });
                        }}
                        className="rounded-md border px-3 py-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]"
                    >
                        Clear filters
                    </button>
                )}
            </div>

            {isError && (
                <p role="alert" className="mb-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {apiErrorMessage(error, "Couldn't load products.")}
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
                                    {hasFilters ? 'No products match these filters.' : 'No products yet.'}
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
                        <button
                            type="button"
                            disabled={page <= 1}
                            onClick={() => setSearch({ page: page - 1 })}
                            className="rounded-md border px-2 py-1 disabled:opacity-40"
                        >
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

            <ProductFormDialog target={formTarget} onClose={() => setFormTarget(null)} />

            <Modal open={pendingDelete !== null} onClose={closeDelete} title="Delete product?">
                <p className="text-sm text-[var(--color-ink-muted)]">
                    &ldquo;{pendingDelete?.name}&rdquo; and its variants will be removed from the catalog. This cannot be undone.
                </p>
                {del.isError && (
                    <p role="alert" className="mt-3 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                        {apiErrorMessage(del.error, 'Could not delete the product. Please try again.')}
                    </p>
                )}
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={closeDelete} disabled={del.isPending} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={confirmDelete}
                        disabled={del.isPending}
                        className="rounded-md bg-red-600 px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {del.isPending ? 'Deleting...' : 'Delete'}
                    </button>
                </div>
            </Modal>
        </div>
    );
}
