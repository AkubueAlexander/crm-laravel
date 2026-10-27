$ErrorActionPreference = 'Stop'
$repo = 'C:\wamp64\www\crm-erp'
if (-not (Test-Path (Join-Path $repo 'artisan'))) { throw 'artisan not found in repo' }
Set-Location $repo
$enc = New-Object System.Text.UTF8Encoding($false)

function Write-NoBom([string]$Path, [string]$Content) {
    if (-not [System.IO.Path]::IsPathRooted($Path)) { $Path = Join-Path $repo $Path }
    $dir = Split-Path -Parent $Path
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    $c = $Content.Replace("`r`n", "`n")
    if (-not $c.EndsWith("`n")) { $c += "`n" }
    [System.IO.File]::WriteAllText($Path, $c, $enc)
}
function Read-Utf8([string]$p) { [System.IO.File]::ReadAllText($p, $enc) }
function Count-Sub([string]$s, [string]$sub) {
    $n = 0; $i = 0
    while (($i = $s.IndexOf($sub, $i, [StringComparison]::Ordinal)) -ge 0) { $n++; $i += $sub.Length }
    return $n
}
function Patch-Once([string]$Path, [string]$Anchor, [string]$Replacement, [string]$Skip) {
    $raw = Read-Utf8 $Path
    $crlf = $raw.Contains("`r`n")
    $t = $raw.Replace("`r`n", "`n")
    $a = $Anchor.Replace("`r`n", "`n")
    $r = $Replacement.Replace("`r`n", "`n")
    if ($t.Contains($Skip)) { return 'skipped' }
    if ((Count-Sub $t $a) -ne 1) { throw "Anchor not exactly once in $Path : $a" }
    $t = $t.Replace($a, $r)
    if ($crlf) { $t = $t.Replace("`n", "`r`n") }
    [System.IO.File]::WriteAllText($Path, $t, $enc)
    return 'patched'
}

$routerTsx = Join-Path $repo 'frontend\src\router.tsx'
$appShell = Join-Path $repo 'frontend\src\shared\components\AppShell.tsx'
foreach ($p in @($routerTsx, $appShell)) { if (-not (Test-Path $p)) { throw "Missing $p" } }

$patches = New-Object System.Collections.Generic.List[object]

$patches.Add(@{ Path = $routerTsx; Skip = 'import { ProductsPage }'
Anchor = @'
import { CampaignDashboard } from '@/features/marketing/components/CampaignDashboard';
'@
Replacement = @'
import { CampaignDashboard } from '@/features/marketing/components/CampaignDashboard';
import { ProductsPage } from '@/features/products/components/ProductsPage';
import { validateProductsSearch } from '@/features/products/schemas/product';
'@ })

$patches.Add(@{ Path = $routerTsx; Skip = 'const productsRoute'
Anchor = @'
// 9.10: campaign dashboard.
'@
Replacement = @'
// 10.0c: product catalog. Filters, sort and page live in the URL (8.7).
const productsRoute = createRoute({
    getParentRoute: () => authenticatedLayoutRoute,
    path: '/products',
    validateSearch: validateProductsSearch,
    component: ProductsPage,
});

// 9.10: campaign dashboard.
'@ })

$patches.Add(@{ Path = $routerTsx; Skip = 'campaignDashboardRoute, productsRoute'
Anchor = @'
leadFormsRoute, campaignDashboardRoute]),
'@
Replacement = @'
leadFormsRoute, campaignDashboardRoute, productsRoute]),
'@ })

$patches.Add(@{ Path = $appShell; Skip = "label: 'Products'"
Anchor = @'
{ label: 'Marketing', to: '/marketing', permission: 'marketing.view' },
'@
Replacement = @'
{ label: 'Marketing', to: '/marketing', permission: 'marketing.view' },
    { label: 'Products', to: '/products', permission: 'products.view' },
'@ })

foreach ($p in $patches) {
    $t = (Read-Utf8 $p.Path).Replace("`r`n", "`n")
    if ($t.Contains($p.Skip)) { continue }
    $n = Count-Sub $t ($p.Anchor.Replace("`r`n", "`n"))
    if ($n -ne 1) { throw "PREFLIGHT: anchor occurs $n times in $($p.Path): $($p.Anchor)" }
}

# ---------------- files ----------------
Write-NoBom 'frontend\src\features\products\schemas\product.ts' @'
import { z } from 'zod';
import { paginationMetaSchema } from '@/shared/schemas/pagination';

export const TAX_CATEGORIES = ['standard', 'reduced', 'zero', 'exempt'] as const;
export type TaxCategory = (typeof TAX_CATEGORIES)[number];

export const productVariantSchema = z.object({
    id: z.number(),
    sku: z.string(),
    name: z.string(),
    unit_price: z.string().nullable(),
    is_active: z.boolean(),
});

// Mirrors ProductResource (10.0b). Money is a decimal string ("19.90"), never a float.
// variants_count is present on list rows, variants on show/store/update responses.
export const productSchema = z.object({
    id: z.number(),
    sku: z.string(),
    name: z.string(),
    description: z.string().nullable(),
    unit_price: z.string(),
    tax_category: z.enum(TAX_CATEGORIES),
    is_active: z.boolean(),
    variants_count: z.number().optional(),
    variants: z.array(productVariantSchema).optional(),
    created_at: z.string().nullable(),
    updated_at: z.string().nullable(),
});

export type Product = z.infer<typeof productSchema>;
export type ProductVariant = z.infer<typeof productVariantSchema>;

export const productsPageSchema = z.object({
    data: z.array(productSchema),
    meta: paginationMetaSchema,
});

export type ProductsPage = z.infer<typeof productsPageSchema>;

export const PRODUCT_SORT_COLUMNS = ['name', 'sku', 'unit_price', 'created_at'] as const;
export type ProductSortColumn = (typeof PRODUCT_SORT_COLUMNS)[number];

export type ProductStatusFilter = 'active' | 'inactive';

// URL-persisted filter/sort/page state for /products (8.7). Every field optional; defaults are dropped.
export type ProductsSearch = {
    q?: string;
    status?: ProductStatusFilter;
    tax_category?: TaxCategory;
    sort?: ProductSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};

// Used as the route's validateSearch: never throws, drops anything malformed or empty.
export function validateProductsSearch(search: Record<string, unknown>): ProductsSearch {
    const out: ProductsSearch = {};

    // The router JSON-parses search values, so "123" can arrive as a number.
    const rawQ = typeof search.q === 'number' ? String(search.q) : search.q;
    if (typeof rawQ === 'string' && rawQ.trim() !== '') out.q = rawQ.trim();

    if (search.status === 'active' || search.status === 'inactive') out.status = search.status;
    if (TAX_CATEGORIES.includes(search.tax_category as TaxCategory)) out.tax_category = search.tax_category as TaxCategory;
    if (PRODUCT_SORT_COLUMNS.includes(search.sort as ProductSortColumn)) out.sort = search.sort as ProductSortColumn;
    if (search.direction === 'asc' || search.direction === 'desc') out.direction = search.direction;

    const page = Number(search.page);
    if (Number.isInteger(page) && page > 1) out.page = page;

    return out;
}
'@

Write-NoBom 'frontend\src\features\products\schemas\productForm.ts' @'
import { z } from 'zod';
import { TAX_CATEGORIES } from './product';

// UX layer only: mirrors SaveProductRequest + SaveProductAction (10.0a/b). The API is the security boundary;
// SKU uniqueness across the tenant can only be enforced server-side (mapped back via 422).
const MONEY = /^\d{1,10}(\.\d{1,2})?$/;
const moneyMessage = 'Enter a valid price (max 2 decimals).';

const price = z
    .string()
    .trim()
    .min(1, 'Enter a price.')
    .refine((v) => MONEY.test(v), moneyMessage);

// Blank = the variant inherits the product price.
const optionalPrice = z
    .string()
    .trim()
    .refine((v) => v === '' || MONEY.test(v), moneyMessage);

// variant_id (not "id"): useFieldArray reserves `id` as its own row key.
export const variantFormSchema = z.object({
    variant_id: z.number().nullable(),
    sku: z.string().trim().min(1, 'SKU is required.').max(64, 'SKU must be 64 characters or fewer.'),
    name: z.string().trim().min(1, 'Name is required.').max(160, 'Name must be 160 characters or fewer.'),
    unit_price: optionalPrice,
    is_active: z.boolean(),
});

export const productFormSchema = z
    .object({
        sku: z.string().trim().min(1, 'SKU is required.').max(64, 'SKU must be 64 characters or fewer.'),
        name: z.string().trim().min(1, 'Name is required.').max(160, 'Name must be 160 characters or fewer.'),
        description: z.string().trim().max(5000, 'Description must be 5000 characters or fewer.'),
        unit_price: price,
        tax_category: z.enum(TAX_CATEGORIES),
        is_active: z.boolean(),
        variants: z.array(variantFormSchema).max(50, 'A product can have at most 50 variants.'),
    })
    .superRefine((v, ctx) => {
        const seen = new Set<string>([v.sku.toLowerCase()]);
        v.variants.forEach((variant, i) => {
            const key = variant.sku.toLowerCase();
            if (key !== '' && seen.has(key)) {
                ctx.addIssue({ code: z.ZodIssueCode.custom, path: ['variants', i, 'sku'], message: 'Duplicate SKU in this product.' });
            }
            seen.add(key);
        });
    });

export type ProductFormValues = z.infer<typeof productFormSchema>;

// What the API receives. Money stays a string; blank description/variant price become null.
export type ProductInput = {
    sku: string;
    name: string;
    description: string | null;
    unit_price: string;
    tax_category: ProductFormValues['tax_category'];
    is_active: boolean;
    variants: Array<{
        id?: number;
        sku: string;
        name: string;
        unit_price: string | null;
        is_active: boolean;
    }>;
};

export function toProductInput(values: ProductFormValues): ProductInput {
    const description = values.description.trim();
    return {
        sku: values.sku.trim(),
        name: values.name.trim(),
        description: description === '' ? null : description,
        unit_price: values.unit_price.trim(),
        tax_category: values.tax_category,
        is_active: values.is_active,
        variants: values.variants.map((v) => ({
            ...(v.variant_id !== null ? { id: v.variant_id } : {}),
            sku: v.sku.trim(),
            name: v.name.trim(),
            unit_price: v.unit_price.trim() === '' ? null : v.unit_price.trim(),
            is_active: v.is_active,
        })),
    };
}
'@

Write-NoBom 'frontend\src\features\products\api\useProducts.ts' @'
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import {
    productSchema,
    productsPageSchema,
    type Product,
    type ProductSortColumn,
    type ProductStatusFilter,
    type ProductsPage,
    type TaxCategory,
} from '../schemas/product';

export type ProductsListParams = {
    page?: number;
    perPage?: number;
    sort?: ProductSortColumn;
    direction?: 'asc' | 'desc';
    q?: string;
    status?: ProductStatusFilter;
    taxCategory?: TaxCategory;
    enabled?: boolean;
};

// Tenant-namespaced keys (1.2). The root prefix covers lists and details: mutations invalidate it as a whole.
export function productsRootKey(tenantId: unknown) {
    return ['products', tenantId] as const;
}

export function productsListKey(tenantId: unknown) {
    return [...productsRootKey(tenantId), 'list'] as const;
}

export function useProducts(params: ProductsListParams = {}) {
    const { tenant } = useTenant();
    const { page = 1, perPage = 25, sort = 'name', direction = 'asc', q = '', status, taxCategory, enabled = true } = params;

    return useQuery({
        queryKey: [...productsListKey(tenant?.id), { page, perPage, sort, direction, q, status, taxCategory }] as const,
        queryFn: async (): Promise<ProductsPage> => {
            const { data } = await apiClient.get('/products', {
                params: {
                    page,
                    per_page: perPage,
                    sort,
                    direction,
                    q: q || undefined,
                    status: status || undefined,
                    tax_category: taxCategory || undefined,
                },
            });
            return productsPageSchema.parse(data);
        },
        enabled: enabled && !!tenant?.id,
        placeholderData: keepPreviousData,
    });
}

// The list carries variants_count only; the edit dialog needs the variants, so it loads the detail fresh.
export function useProduct(productId: number | null) {
    const { tenant } = useTenant();

    return useQuery({
        queryKey: [...productsRootKey(tenant?.id), 'detail', productId] as const,
        queryFn: async (): Promise<Product> => {
            const { data } = await apiClient.get(`/products/${productId}`);
            return productSchema.parse(data);
        },
        enabled: !!tenant?.id && productId !== null,
        staleTime: 0,
    });
}
'@

Write-NoBom 'frontend\src\features\products\api\useProductMutations.ts' @'
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { apiClient } from '@/shared/lib/apiClient';
import { useTenant } from '@/shared/hooks/useTenant';
import { productSchema, type Product } from '../schemas/product';
import type { ProductInput } from '../schemas/productForm';
import { productsRootKey } from './useProducts';

type SaveVars = { id: number | null; input: ProductInput };

// Not optimistic: the server owns the SKU-uniqueness rule, so the row appears only once the API accepted it.
// Tenant comes from the session, never from the body (1.3).
export function useSaveProduct() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async ({ id, input }: SaveVars): Promise<Product> => {
            const { data } = id === null ? await apiClient.post('/products', input) : await apiClient.put(`/products/${id}`, input);
            return productSchema.parse(data);
        },
        onSuccess: () => queryClient.invalidateQueries({ queryKey: productsRootKey(tenant?.id) }),
    });
}

// Not optimistic: the user confirms first, and the server may still refuse (e.g. once quotes reference a product).
export function useDeleteProduct() {
    const queryClient = useQueryClient();
    const { tenant } = useTenant();

    return useMutation({
        mutationFn: async (productId: number): Promise<void> => {
            await apiClient.delete(`/products/${productId}`);
        },
        // Also on error: a refusal usually means the list on screen is stale.
        onSettled: () => queryClient.invalidateQueries({ queryKey: productsRootKey(tenant?.id) }),
    });
}
'@

Write-NoBom 'frontend\src\features\products\components\ProductFormDialog.tsx' @'
import { useEffect, useState } from 'react';
import { useFieldArray, useForm, type FieldPath } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { isAxiosError } from 'axios';
import { Modal } from '@/shared/components/Modal';
import { apiErrorMessage } from '@/shared/lib/apiErrorMessage';
import { useProduct } from '../api/useProducts';
import { useSaveProduct } from '../api/useProductMutations';
import { TAX_CATEGORIES, type Product } from '../schemas/product';
import { productFormSchema, toProductInput, type ProductFormValues } from '../schemas/productForm';

type Props = {
    // null = closed, 'new' = create, Product = edit (its variants are loaded fresh).
    target: Product | 'new' | null;
    onClose: () => void;
};

// First message per field from a 422 body shaped { message, errors: { field: [msg, ...] }, code }.
function serverFieldErrors(error: unknown): Record<string, string> {
    if (!isAxiosError(error) || error.response?.status !== 422) return {};
    const errors = (error.response.data as { errors?: Record<string, unknown> } | undefined)?.errors;
    if (!errors || typeof errors !== 'object') return {};
    const out: Record<string, string> = {};
    for (const [field, messages] of Object.entries(errors)) {
        const first = Array.isArray(messages) ? messages[0] : messages;
        if (typeof first === 'string') out[field] = first;
    }
    return out;
}

const FORM_FIELD = /^(sku|name|description|unit_price|tax_category|variants\.\d+\.(sku|name|unit_price))$/;
const INPUT = 'w-full rounded-md border px-3 py-2 text-sm';

function FieldError({ message }: { message?: string }) {
    if (!message) return null;
    return (
        <p role="alert" className="mt-1 text-xs text-red-700">
            {message}
        </p>
    );
}

function ProductForm({ product, onClose }: { product: Product | null; onClose: () => void }) {
    const save = useSaveProduct();
    const [formError, setFormError] = useState<string | null>(null);

    const {
        register,
        control,
        handleSubmit,
        setError,
        setFocus,
        formState: { errors, isSubmitting },
    } = useForm<ProductFormValues>({
        resolver: zodResolver(productFormSchema),
        defaultValues: {
            sku: product?.sku ?? '',
            name: product?.name ?? '',
            description: product?.description ?? '',
            unit_price: product?.unit_price ?? '',
            tax_category: product?.tax_category ?? 'standard',
            is_active: product?.is_active ?? true,
            variants: (product?.variants ?? []).map((v) => ({
                variant_id: v.id,
                sku: v.sku,
                name: v.name,
                unit_price: v.unit_price ?? '',
                is_active: v.is_active,
            })),
        },
    });

    // 10.0c: each variant row is uncontrolled, so typing in one row does not re-render the others.
    const { fields, append, remove } = useFieldArray({ control, name: 'variants' });

    useEffect(() => {
        setFocus('sku');
    }, [setFocus]);

    const onSubmit = handleSubmit(async (values) => {
        setFormError(null);
        try {
            await save.mutateAsync({ id: product?.id ?? null, input: toProductInput(values) });
            onClose();
        } catch (error) {
            const fields422 = serverFieldErrors(error);
            let handled = false;
            for (const [field, message] of Object.entries(fields422)) {
                if (FORM_FIELD.test(field)) {
                    setError(field as FieldPath<ProductFormValues>, { type: 'server', message });
                    handled = true;
                }
            }
            if (!handled) {
                const other = Object.values(fields422)[0];
                setFormError(other ?? apiErrorMessage(error, 'Could not save the product. Please try again.'));
            }
        }
    });

    const variantsError = typeof errors.variants?.message === 'string' ? errors.variants.message : undefined;

    return (
        <form onSubmit={onSubmit} noValidate className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-3">
                <div>
                    <label htmlFor="product-sku" className="mb-1 block text-sm font-medium">
                        SKU
                    </label>
                    <input id="product-sku" type="text" autoComplete="off" aria-invalid={errors.sku ? true : undefined} className={INPUT} {...register('sku')} />
                    <FieldError message={errors.sku?.message} />
                </div>
                <div>
                    <label htmlFor="product-price" className="mb-1 block text-sm font-medium">
                        Unit price
                    </label>
                    <input
                        id="product-price"
                        type="text"
                        inputMode="decimal"
                        autoComplete="off"
                        aria-invalid={errors.unit_price ? true : undefined}
                        className={INPUT}
                        {...register('unit_price')}
                    />
                    <FieldError message={errors.unit_price?.message} />
                </div>
            </div>

            <div>
                <label htmlFor="product-name" className="mb-1 block text-sm font-medium">
                    Name
                </label>
                <input id="product-name" type="text" autoComplete="off" aria-invalid={errors.name ? true : undefined} className={INPUT} {...register('name')} />
                <FieldError message={errors.name?.message} />
            </div>

            <div>
                <label htmlFor="product-description" className="mb-1 block text-sm font-medium">
                    Description <span className="font-normal text-[var(--color-ink-muted)]">(optional)</span>
                </label>
                <textarea id="product-description" rows={3} className={INPUT} {...register('description')} />
                <FieldError message={errors.description?.message} />
            </div>

            <div className="grid grid-cols-2 items-end gap-3">
                <div>
                    <label htmlFor="product-tax" className="mb-1 block text-sm font-medium">
                        Tax category
                    </label>
                    <select id="product-tax" className={INPUT} {...register('tax_category')}>
                        {TAX_CATEGORIES.map((c) => (
                            <option key={c} value={c}>
                                {c.charAt(0).toUpperCase() + c.slice(1)}
                            </option>
                        ))}
                    </select>
                </div>
                <label className="flex items-center gap-2 pb-2 text-sm">
                    <input type="checkbox" {...register('is_active')} />
                    Active
                </label>
            </div>

            <fieldset className="rounded-md border border-[var(--color-border)] p-3">
                <legend className="px-1 text-sm font-medium">Variants</legend>

                {fields.length === 0 && <p className="text-xs text-[var(--color-ink-muted)]">No variants. The product itself is what gets sold.</p>}

                <div className="flex flex-col gap-3">
                    {fields.map((field, i) => (
                        <div key={field.id} className="rounded-md border border-[var(--color-border)] p-2">
                            <div className="grid grid-cols-2 gap-2">
                                <div>
                                    <input
                                        type="text"
                                        aria-label={`Variant ${i + 1} SKU`}
                                        placeholder="SKU"
                                        autoComplete="off"
                                        aria-invalid={errors.variants?.[i]?.sku ? true : undefined}
                                        className={INPUT}
                                        {...register(`variants.${i}.sku` as const)}
                                    />
                                    <FieldError message={errors.variants?.[i]?.sku?.message} />
                                </div>
                                <div>
                                    <input
                                        type="text"
                                        aria-label={`Variant ${i + 1} name`}
                                        placeholder="Name"
                                        autoComplete="off"
                                        aria-invalid={errors.variants?.[i]?.name ? true : undefined}
                                        className={INPUT}
                                        {...register(`variants.${i}.name` as const)}
                                    />
                                    <FieldError message={errors.variants?.[i]?.name?.message} />
                                </div>
                                <div>
                                    <input
                                        type="text"
                                        inputMode="decimal"
                                        aria-label={`Variant ${i + 1} price`}
                                        placeholder="Price (blank = product price)"
                                        autoComplete="off"
                                        aria-invalid={errors.variants?.[i]?.unit_price ? true : undefined}
                                        className={INPUT}
                                        {...register(`variants.${i}.unit_price` as const)}
                                    />
                                    <FieldError message={errors.variants?.[i]?.unit_price?.message} />
                                </div>
                                <div className="flex items-center justify-between">
                                    <label className="flex items-center gap-2 text-sm">
                                        <input type="checkbox" {...register(`variants.${i}.is_active` as const)} />
                                        Active
                                    </label>
                                    <button type="button" onClick={() => remove(i)} className="rounded-md border px-2 py-1 text-xs text-red-700">
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    ))}
                </div>

                <FieldError message={variantsError} />

                <button
                    type="button"
                    onClick={() => append({ variant_id: null, sku: '', name: '', unit_price: '', is_active: true })}
                    disabled={fields.length >= 50}
                    className="mt-3 rounded-md border px-2 py-1 text-xs disabled:opacity-40"
                >
                    Add variant
                </button>
            </fieldset>

            {formError && (
                <p role="alert" className="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700">
                    {formError}
                </p>
            )}

            <div className="flex justify-end gap-2">
                <button type="button" onClick={onClose} disabled={isSubmitting} className="rounded-md border px-3 py-1.5 text-sm disabled:opacity-50">
                    Cancel
                </button>
                <button
                    type="submit"
                    disabled={isSubmitting}
                    className="rounded-md bg-[var(--color-ink)] px-3 py-1.5 text-sm text-white hover:opacity-90 disabled:opacity-50"
                >
                    {isSubmitting ? 'Saving...' : product ? 'Save changes' : 'Create product'}
                </button>
            </div>
        </form>
    );
}

function EditProduct({ summary, onClose }: { summary: Product; onClose: () => void }) {
    const detail = useProduct(summary.id);

    return (
        <Modal open onClose={onClose} title="Edit product">
            {detail.isPending ? (
                <p className="text-sm text-[var(--color-ink-muted)]">Loading...</p>
            ) : detail.isError ? (
                <div className="flex flex-col gap-3">
                    <p role="alert" className="text-sm text-red-700">
                        {apiErrorMessage(detail.error, 'Could not load the product.')}
                    </p>
                    <button type="button" onClick={onClose} className="self-end rounded-md border px-3 py-1.5 text-sm">
                        Close
                    </button>
                </div>
            ) : (
                <ProductForm key={detail.data.id} product={detail.data} onClose={onClose} />
            )}
        </Modal>
    );
}

export function ProductFormDialog({ target, onClose }: Props) {
    if (target === null) return null;

    if (target === 'new') {
        return (
            <Modal open onClose={onClose} title="New product">
                <ProductForm key="new" product={null} onClose={onClose} />
            </Modal>
        );
    }

    return <EditProduct key={target.id} summary={target} onClose={onClose} />;
}
'@

Write-NoBom 'frontend\src\features\products\components\ProductsPage.tsx' @'
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
'@

foreach ($p in $patches) {
    $res = Patch-Once $p.Path $p.Anchor $p.Replacement $p.Skip
    Write-Host "$res : $($p.Path)"
}

$report = New-Object System.Collections.Generic.List[string]
function Say([string]$m) { Write-Host $m; $report.Add($m) }

$ErrorActionPreference = 'Continue'
Push-Location (Join-Path $repo 'frontend')
$tsc = (& npx.cmd tsc --noEmit 2>&1 | Out-String)
$tscExit = $LASTEXITCODE
Pop-Location
Say "tsc exit: $tscExit"
if ($tscExit -ne 0) { Say $tsc }

$ErrorActionPreference = 'Stop'
Set-Clipboard -Value ($report -join "`r`n")
Write-Host 'Report copied'
Read-Host 'Press Enter to close'
Write-Host 'NOT TOUCHED (referenced, not shown): useTenant, apiClient, useDebouncedValue, backend files (no backend change in this chunk).'
