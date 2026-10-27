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
