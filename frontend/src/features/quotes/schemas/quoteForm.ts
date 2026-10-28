import { z } from 'zod';
import { TAX_CATEGORIES, type TaxCategory } from '@/features/products/schemas/product';
import { formatMoney, type Quote } from './quote';

// UX layer only: mirrors SaveQuoteRequest + SaveQuoteAction (10.1a/b). The API is the security boundary and
// computes every stored total; ownership of account/contact/product/variant is checked server-side (422).
const MONEY = /^\d{1,10}(\.\d{1,2})?$/;
const QTY = /^\d{1,6}(\.\d{1,3})?$/;
const PCT = /^\d{1,3}(\.\d{1,2})?$/;

export const quoteLineFormSchema = z.object({
    product_id: z.number().nullable(),
    variant_id: z.number().nullable(),
    sku: z.string(),
    name: z.string().trim().min(1, 'Name is required.').max(160, 'Name must be 160 characters or fewer.'),
    description: z.string().trim().max(2000, 'Description must be 2000 characters or fewer.'),
    quantity: z
        .string()
        .trim()
        .refine((v) => QTY.test(v) && Number(v) > 0, 'Quantity must be greater than 0 (max 3 decimals).'),
    unit_price: z
        .string()
        .trim()
        .min(1, 'Enter a price.')
        .refine((v) => MONEY.test(v), 'Enter a valid price (max 2 decimals).'),
    discount_percent: z
        .string()
        .trim()
        .refine((v) => v === '' || (PCT.test(v) && Number(v) <= 100), 'Discount must be between 0 and 100.'),
    tax_category: z.enum(TAX_CATEGORIES),
});

export const quoteFormSchema = z.object({
    title: z.string().trim().min(1, 'Title is required.').max(160, 'Title must be 160 characters or fewer.'),
    currency: z.string().trim().regex(/^[A-Z]{3}$/, 'Use a 3-letter currency code, e.g. USD.'),
    valid_until: z.string().trim().refine((v) => v === '' || /^\d{4}-\d{2}-\d{2}$/.test(v), 'Use a valid date.'),
    notes: z.string().trim().max(5000, 'Notes must be 5000 characters or fewer.'),
    terms: z.string().trim().max(10000, 'Terms must be 10000 characters or fewer.'),
    // Carried through untouched: there is no picker for these yet, and omitting them on update would clear them.
    account_id: z.number().nullable(),
    contact_id: z.number().nullable(),
    lines: z.array(quoteLineFormSchema).max(100, 'A quote can have at most 100 lines.'),
});

export type QuoteFormValues = z.infer<typeof quoteFormSchema>;
export type QuoteLineFormValues = z.infer<typeof quoteLineFormSchema>;

export function emptyQuoteValues(): QuoteFormValues {
    return { title: '', currency: 'USD', valid_until: '', notes: '', terms: '', account_id: null, contact_id: null, lines: [] };
}

function plainNumber(value: string): string {
    const n = Number(value);
    return Number.isNaN(n) ? value : String(n);
}

export function valuesFromQuote(quote: Quote): QuoteFormValues {
    return {
        title: quote.title,
        currency: quote.currency,
        valid_until: quote.valid_until ?? '',
        notes: quote.notes ?? '',
        terms: quote.terms ?? '',
        account_id: quote.account_id,
        contact_id: quote.contact_id,
        lines: (quote.lines ?? []).map((l) => ({
            product_id: l.product_id,
            variant_id: l.variant_id,
            sku: l.sku ?? '',
            name: l.name,
            description: l.description ?? '',
            quantity: plainNumber(l.quantity),
            unit_price: l.unit_price,
            discount_percent: Number(l.discount_percent) === 0 ? '' : plainNumber(l.discount_percent),
            tax_category: l.tax_category,
        })),
    };
}

// What the API receives. Catalog lines send ids (the server takes sku/name/tax from the catalog); custom lines
// send their own name and tax category. Totals are never sent.
export type QuoteInput = {
    title: string;
    currency: string;
    valid_until: string | null;
    notes: string | null;
    terms: string | null;
    account_id: number | null;
    contact_id: number | null;
    lines: Array<Record<string, unknown>>;
};

export function toQuoteInput(values: QuoteFormValues): QuoteInput {
    const orNull = (s: string) => (s.trim() === '' ? null : s.trim());

    return {
        title: values.title.trim(),
        currency: values.currency.trim(),
        valid_until: orNull(values.valid_until),
        notes: orNull(values.notes),
        terms: orNull(values.terms),
        account_id: values.account_id,
        contact_id: values.contact_id,
        lines: values.lines.map((l) => {
            const common = {
                description: orNull(l.description),
                quantity: l.quantity.trim(),
                unit_price: l.unit_price.trim(),
                discount_percent: l.discount_percent.trim() === '' ? '0' : l.discount_percent.trim(),
            };
            if (l.product_id !== null) {
                return { product_id: l.product_id, variant_id: l.variant_id, ...common };
            }
            return { name: l.name.trim(), tax_category: l.tax_category, ...common };
        }),
    };
}

// ---- Live totals preview (display only; the server total is authoritative) ----
// Same arithmetic and rounding as App\Domain\Quotes\QuoteTotals: integer cents, half-up, per-line tax.
export const RATES_BPS: Record<TaxCategory, number> = { standard: 750, reduced: 500, zero: 0, exempt: 0 };

type PreviewLine = Pick<QuoteLineFormValues, 'quantity' | 'unit_price' | 'discount_percent' | 'tax_category'>;

export function previewTotals(lines: PreviewLine[]): { subtotalCents: number; taxCents: number; totalCents: number } {
    let subtotal = 0;
    let tax = 0;

    for (const l of lines) {
        const qty = Number(l.quantity);
        const price = Number(l.unit_price);
        const discount = l.discount_percent.trim() === '' ? 0 : Number(l.discount_percent);
        if (![qty, price, discount].every(Number.isFinite) || qty <= 0 || price < 0 || discount < 0 || discount > 100) continue;

        const unitCents = Math.round(price * 100);
        const net = Math.round((qty * unitCents * (100 - discount)) / 100);
        tax += Math.round((net * (RATES_BPS[l.tax_category] ?? 0)) / 10000);
        subtotal += net;
    }

    return { subtotalCents: subtotal, taxCents: tax, totalCents: subtotal + tax };
}

export function formatCents(cents: number, currency: string): string {
    return formatMoney(cents / 100, /^[A-Z]{3}$/.test(currency) ? currency : 'USD');
}
