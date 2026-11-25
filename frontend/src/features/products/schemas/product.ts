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


export type ProductsSearch = {
    q?: string;
    status?: ProductStatusFilter;
    tax_category?: TaxCategory;
    sort?: ProductSortColumn;
    direction?: 'asc' | 'desc';
    page?: number;
};


export function validateProductsSearch(search: Record<string, unknown>): ProductsSearch {
    const out: ProductsSearch = {};

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
